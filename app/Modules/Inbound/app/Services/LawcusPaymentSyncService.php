<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Audit\Models\AuditLog;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Mail\LawcusRecordPaymentNeededMail;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\EmailConfiguration;
use Modules\Inbound\Models\LawcusConnection;
use RuntimeException;
use Throwable;

/**
 * Records a captured payment against its invoice in Lawcus.
 *
 * Lawcus documents no payment endpoint; this mirrors what the Lawcus web app's own
 * "Record payment" dialog sends (POST /accounts/transactions with a payments[] list),
 * which accepts pasted API tokens on the REST host. Because that endpoint can change
 * without notice, every recording is confirmed by re-reading the invoice's amount due,
 * and any payment that isn't confirmed is sent to the firm to record by hand.
 */
class LawcusPaymentSyncService
{
    /** Audit event marking that the firm was already asked to record this payment by hand. */
    public const ALERT_SENT_EVENT = 'LAWCUS_RECORD_PAYMENT_ALERT_SENT';

    public function __construct(
        private readonly LawcusApiClient $api,
        private readonly LawcusMatterActivityService $matterActivity,
    ) {}

    /**
     * Records the charge on its invoice and, for "all outstanding", on each other invoice
     * it covered. Each invoice is recorded independently, so one failure doesn't hold the
     * others back; retries (listener re-run, sync command) skip the ones already recorded.
     *
     * @return bool true when every payment is (now or already) recorded in Lawcus
     */
    public function sync(Transaction $transaction, Invoice $invoice): bool
    {
        $client = Client::query()->where('pms_client_id', $invoice->pms_client_id)->first();
        $failed = false;

        foreach ($this->targets($transaction, $invoice) as [$target, $portion, $covered]) {
            try {
                $this->recordOn($client, $transaction, $target, $portion, $covered);
            } catch (Throwable $exception) {
                $failed = true;

                AuditLogger::log('PMS_PAYMENT_RECORD_FAILED', 'invoice', $invoice->id, [
                    'pms_source'          => 'lawcus',
                    'transaction_id'      => $transaction->id,
                    'gateway'             => $transaction->gateway,
                    'external_invoice_id' => $target->external_invoice_id,
                    'invoice_number'      => $target->invoice_number,
                    'covered'             => $covered,
                    'error'               => $exception->getMessage(),
                ]);

                // Cases the firm must review rather than simply record (e.g. it could double
                // a payment, or put operating money into trust) get no "record it" alert.
                if (! $exception instanceof LawcusPaymentNeedsReview) {
                    $this->askFirmToRecord($client, $transaction, $target, $portion);
                }
            }
        }

        $invoice->forceFill(['pms_sync_status' => $failed ? 'FAILED' : 'SYNCED'])->save();

        return ! $failed;
    }

    /**
     * @return list<array{0: Invoice, 1: float, 2: bool}> each invoice to record, its share of the charge, and whether it was covered
     */
    private function targets(Transaction $transaction, Invoice $invoice): array
    {
        $targets = [[$invoice, $this->invoicePortion($transaction, $invoice), false]];

        foreach ((array) $transaction->covered_invoices as $covered) {
            $targets[] = [
                $this->coveredInvoice($invoice, (array) $covered),
                round((int) ($covered['amount_cents'] ?? 0) / 100, 2),
                true,
            ];
        }

        return $targets;
    }

    /**
     * Records $portion of the charge as a payment on one Lawcus invoice, then confirms it.
     * Retries find the earlier payment by its reference and skip it.
     *
     * A covered invoice that has nothing left to pay by now (e.g. the firm recorded a check
     * meanwhile) means the client paid it twice: that's flagged for the firm to refund rather
     * than failed, since no retry can fix it.
     */
    private function recordOn(?Client $client, Transaction $transaction, Invoice $invoice, float $portion, bool $covered): void
    {
        // Checkout never lets a Trust Request into an operating charge; if one got here
        // anyway, don't record operating money into trust — leave it FAILED for review.
        if ($covered && $invoice->fund_type === 'TRUST') {
            throw new LawcusPaymentNeedsReview("Trust Request {$invoice->invoice_number} was covered by an operating charge; not recorded in Lawcus.");
        }

        if (! $client) {
            throw new RuntimeException('No SimpHub client found for this Lawcus invoice.');
        }

        $connection  = $this->connection((string) $invoice->pms_client_id);
        $accountId   = $this->destinationAccountId($client, $invoice);
        $invoiceUuid = (string) $invoice->external_invoice_id;
        $current     = $this->api->fetchBill($connection, $invoiceUuid);

        if ($this->alreadyRecorded($current, $transaction)) {
            return;
        }

        $amountDue = (float) Arr::get($current, 'data.amount_due', 0);
        $amount    = min($portion, $amountDue);

        if ($amount <= 0 && $covered) {
            AuditLogger::log('LAWCUS_COVERED_INVOICE_ALREADY_PAID', 'invoice', $invoice->id, [
                'transaction_id'      => $transaction->id,
                'external_invoice_id' => $invoiceUuid,
                'invoice_number'      => $invoice->invoice_number,
                'paid_amount'         => $portion,
            ]);

            return;
        }

        if ($amount <= 0) {
            // Possibly recorded already without our reference — asking the firm to record
            // it again could double it.
            throw new LawcusPaymentNeedsReview("Lawcus invoice {$invoice->invoice_number} has nothing left to pay (amount due {$amountDue}).");
        }

        $postError = null;

        try {
            $this->api->recordInvoicePayment($connection, [
                'invoice_id'            => $invoiceUuid,
                'amount'                => $amount,
                'date'                  => ($transaction->created_at ?? now())->copy()->utc()->format('Y-m-d H:i:s'),
                'client_id'             => (int) Arr::get($current, 'data.client_id', $invoice->external_client_id),
                'source_id'             => 'DIRECT',
                'account_id'            => 'DIRECT',
                'destination_id'        => $accountId,
                'source_type'           => $this->isAch($transaction) ? 'ACH' : 'CREDIT_CARD',
                'note'                  => $this->reference($transaction),
                // Trust money must land on the matter's trust balance so the firm can later
                // apply it with "use matter trust balance"; without it Lawcus credits the
                // client's trust account with no matter.
                'matter_id'             => $invoice->fund_type === 'TRUST' && is_numeric($invoice->external_matter_id)
                    ? (int) $invoice->external_matter_id
                    : null,
                'destination_item_id'   => null,
                'destination_item_type' => null,
            ]);
        } catch (Throwable $exception) {
            // A timeout or error response doesn't prove nothing was recorded — check the
            // invoice before asking the firm to record it by hand.
            $postError = $exception;
        }

        if (! $this->confirmRecorded($connection, $invoiceUuid, $transaction, $amountDue, $amount)) {
            throw new RuntimeException($postError
                ? "Lawcus did not record the payment on invoice {$invoice->invoice_number}: {$postError->getMessage()}"
                : "Lawcus accepted the payment request but invoice {$invoice->invoice_number}'s amount due did not drop by {$amount}.");
        }

        AuditLogger::log('PMS_PAYMENT_RECORDED', 'invoice', $invoice->id, [
            'pms_source'          => 'lawcus',
            'transaction_id'      => $transaction->id,
            'gateway'             => $transaction->gateway,
            'gateway_txn_id'      => $transaction->gateway_txn_id,
            'external_invoice_id' => $invoiceUuid,
            'bank_account_id'     => $accountId,
            'amount'              => $amount,
            'covered'             => $covered,
        ]);

        // Only logged when the payment is newly recorded, so retries don't repeat it.
        $this->matterActivity->logPaymentRecorded($transaction, $invoice, $amount, $this->destinationAccountName($client, $invoice));
    }

    /**
     * Lawcus' create response isn't documented (it answers [] either way), so the payment
     * counts as recorded only when the invoice carries it and its amount due dropped by
     * at least the amount paid. The amount due comes from the published Invoice History
     * report; Trust Requests aren't in that report, so theirs (and any invoice the report
     * doesn't list) comes from the invoice itself. A failed read counts as not confirmed.
     */
    private function confirmRecorded(LawcusConnection $connection, string $invoiceUuid, Transaction $transaction, float $amountDueBefore, float $amount): bool
    {
        try {
            $after = $this->api->fetchBill($connection, $invoiceUuid);
        } catch (Throwable) {
            return false;
        }

        $amountDueAfter = null;
        $clientId       = Arr::get($after, 'data.client_id');

        if (is_numeric($clientId)) {
            try {
                $amountDueAfter = $this->api->fetchInvoiceHistoryAmountDue($connection, (int) $clientId, $invoiceUuid, Arr::get($after, 'data.issue_date'));
            } catch (Throwable $exception) {
                // An unreadable report mustn't send the firm to record a payment that the
                // invoice shows did land — that would double it.
                Log::warning('LawcusPaymentSyncService: invoice-history report unavailable; confirming from the invoice', [
                    'transaction_id' => $transaction->id,
                    'error'          => $exception->getMessage(),
                ]);
            }
        }

        $amountDueAfter ??= (float) Arr::get($after, 'data.amount_due', $amountDueBefore);

        return $this->alreadyRecorded($after, $transaction)
            && $amountDueAfter <= round($amountDueBefore - $amount, 2) + 0.005;
    }

    /**
     * Emails the firm (and SimpHub ops) the manual steps to record this payment — once per
     * transaction and invoice, so retries of a failing sync don't repeat it.
     */
    private function askFirmToRecord(?Client $client, Transaction $transaction, Invoice $invoice, float $amount): void
    {
        $alreadySent = AuditLog::query()
            ->where('event_type', self::ALERT_SENT_EVENT)
            ->where('payload->transaction_id', $transaction->id)
            ->where('payload->external_invoice_id', (string) $invoice->external_invoice_id)
            ->exists();

        if ($alreadySent) {
            return;
        }

        $firmEmail  = $this->firmEmail($client);
        $adminEmail = trim((string) config('services.lawcus.ops_alert_email'));
        $recipients = array_values(array_unique(array_filter([$firmEmail, $adminEmail])));

        if ($recipients === []) {
            Log::warning('LawcusPaymentSyncService: payment not recorded in Lawcus and no firm or ops email to alert', [
                'transaction_id' => $transaction->id,
                'invoice_number' => $invoice->invoice_number,
            ]);

            return;
        }

        try {
            Mail::to($recipients)->send(new LawcusRecordPaymentNeededMail(
                $transaction,
                $invoice,
                $client,
                $amount,
                $invoice->fund_type === 'TRUST',
                $this->isAch($transaction) ? 'ACH' : 'card',
                $this->transactionRef($transaction),
            ));
        } catch (Throwable $exception) {
            Log::error('LawcusPaymentSyncService: failed to send the record-payment alert', [
                'transaction_id' => $transaction->id,
                'invoice_number' => $invoice->invoice_number,
                'error'          => $exception->getMessage(),
            ]);

            return;
        }

        AuditLogger::log(self::ALERT_SENT_EVENT, 'invoice', $invoice->id, [
            'transaction_id'      => $transaction->id,
            'external_invoice_id' => (string) $invoice->external_invoice_id,
            'invoice_number'      => $invoice->invoice_number,
            'amount'              => $amount,
            'recipients'          => $recipients,
        ]);
    }

    /** The firm's reply-to address, else its payment-link admin email (as for payment notices). */
    private function firmEmail(?Client $client): string
    {
        if (! $client) {
            return '';
        }

        $email = trim((string) (EmailConfiguration::where('client_id', $client->id)->value('reply_to_email') ?? ''));

        return $email !== '' ? $email : trim((string) ($client->payment_link_admin_email ?? ''));
    }

    private function connection(string $pmsClientId): LawcusConnection
    {
        $connection = LawcusConnection::query()
            ->where('provider', 'lawcus')
            ->where('pms_client_id', $pmsClientId)
            ->latest('created_at')
            ->first();

        if (! $connection) {
            throw new RuntimeException('Lawcus is not connected for this client.');
        }

        return $connection;
    }

    /**
     * A covered invoice as an Invoice: ours when we hold it, otherwise built from what the
     * charge recorded about it (audit entries then go on the invoice that was paid).
     */
    private function coveredInvoice(Invoice $paidFrom, array $covered): Invoice
    {
        $externalId = (string) ($covered['external_invoice_id'] ?? '');

        $local = Invoice::query()
            ->where('pms_source', 'lawcus')
            ->where('pms_client_id', $paidFrom->pms_client_id)
            ->where('external_invoice_id', $externalId)
            ->first();

        if ($local) {
            return $local;
        }

        $invoice = (new Invoice)->forceFill([
            'pms_source'          => 'lawcus',
            'pms_client_id'       => $paidFrom->pms_client_id,
            'external_invoice_id' => $externalId,
            'external_client_id'  => $paidFrom->external_client_id,
            'external_matter_id'  => isset($covered['matter_id']) ? (string) $covered['matter_id'] : null,
            'invoice_number'      => $covered['invoice_number'] ?? null,
            'fund_type'           => 'OPERATING',
            'currency'            => $paidFrom->currency,
            'customer'            => $paidFrom->customer,
            'raw_payload'         => ['invoice' => ['data' => ['client_id' => Arr::get((array) $paidFrom->raw_payload, 'invoice.data.client_id')]]],
        ]);
        $invoice->id = $paidFrom->id;

        return $invoice;
    }

    /**
     * Trust Request payments go to the client's Lawcus trust account; everything else
     * to the default (operating) account. A trust payment never falls back to the
     * operating account — commingling client trust funds is an IOLTA violation.
     */
    private function destinationAccountId(Client $client, Invoice $invoice): string
    {
        if ($invoice->fund_type === 'TRUST') {
            $accountId = trim((string) $client->lawcus_trust_bank_account_id);

            if ($accountId === '') {
                throw new RuntimeException('No Lawcus trust bank account configured for this client; trust payment not recorded.');
            }

            return $accountId;
        }

        $accountId = trim((string) $client->lawcus_default_bank_account_id);

        if ($accountId === '') {
            throw new RuntimeException('No default Lawcus bank account configured for this client.');
        }

        return $accountId;
    }

    private function destinationAccountName(Client $client, Invoice $invoice): string
    {
        $name = $invoice->fund_type === 'TRUST'
            ? trim((string) $client->lawcus_trust_bank_account_name)
            : trim((string) $client->lawcus_default_bank_account_name);

        return $name !== '' ? $name : ($invoice->fund_type === 'TRUST' ? 'Trust' : 'Operating');
    }

    /**
     * What's recorded on the invoice: the invoice amount, never the total charged with the
     * card fee (a $1,000 invoice charged $1,035 records $1,000). The fee is the processor's;
     * booking it on the invoice leaves an overpayment, as it did in QBO. Capped at the
     * invoice amount even when fee_cents is missing or wrong. An "all outstanding" charge
     * also paid other invoices — their share isn't this invoice's.
     */
    private function invoicePortion(Transaction $transaction, Invoice $invoice): float
    {
        $totalCents   = (int) $transaction->amount_cents - $transaction->coveredInvoicesCents();
        $feeCents     = (int) ($transaction->fee_cents ?? 0);
        $invoiceCents = (int) $invoice->amount_cents;

        if ($feeCents === 0 && $totalCents > $invoiceCents) {
            $feeCents = $totalCents - $invoiceCents;
        }

        $cents = $totalCents - $feeCents;

        if ($invoiceCents > 0) {
            $cents = min($cents, $invoiceCents);
        }

        return round(max(0, $cents) / 100, 2);
    }

    /**
     * Ours carries our reference; one the firm recorded by hand from the alert carries
     * "SimpHub transaction <id>" — either means a retry must not record it again.
     */
    private function alreadyRecorded(array $invoicePayload, Transaction $transaction): bool
    {
        $markers = [
            strtolower($this->reference($transaction)),
            strtolower('SimpHub transaction '.$this->transactionRef($transaction)),
        ];

        return collect((array) Arr::get($invoicePayload, 'payments', []))
            ->filter(fn ($payment) => is_array($payment))
            ->contains(fn (array $payment) => str_contains(strtolower((string) ($payment['note'] ?? '')), $markers[0])
                || str_contains(strtolower((string) ($payment['note'] ?? '')), $markers[1]));
    }

    /**
     * Written to the Lawcus payment's note — shows staff where the payment came from
     * and doubles as the duplicate-detection key.
     */
    private function reference(Transaction $transaction): string
    {
        return 'Payment Middleware '.strtoupper((string) $transaction->gateway)
            .' txn '.$this->transactionRef($transaction);
    }

    private function transactionRef(Transaction $transaction): string
    {
        return (string) ($transaction->gateway_txn_id ?: $transaction->id);
    }

    private function isAch(Transaction $transaction): bool
    {
        return strtolower((string) $transaction->gateway) === 'paya'
            || strtoupper((string) $transaction->paymentSession?->payment_method) === 'ACH';
    }
}
