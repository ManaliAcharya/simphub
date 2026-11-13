<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\LawcusConnection;
use RuntimeException;
use Throwable;

/**
 * Records a captured payment against its invoice in Lawcus.
 *
 * Lawcus documents no payment endpoint; this mirrors what the Lawcus web app's own
 * "Record payment" dialog sends (POST /accounts/transactions with a payments[] list),
 * which accepts pasted API tokens on the REST host.
 */
class LawcusPaymentSyncService
{
    public function __construct(
        private readonly LawcusApiClient $api,
        private readonly LawcusMatterActivityService $matterActivity,
    ) {}

    /**
     * @return bool true when the payment is (now or already) recorded in Lawcus
     */
    public function sync(Transaction $transaction, Invoice $invoice): bool
    {
        try {
            $client = Client::query()->where('pms_client_id', $invoice->pms_client_id)->firstOrFail();

            $connection = LawcusConnection::query()
                ->where('provider', 'lawcus')
                ->where('pms_client_id', $invoice->pms_client_id)
                ->latest('created_at')
                ->first();

            if (! $connection) {
                throw new RuntimeException('Lawcus is not connected for this client.');
            }

            $accountId = $this->destinationAccountId($client, $invoice);
            $reference = $this->reference($transaction);

            $this->recordOn($connection, $client, $accountId, $transaction, $invoice, $this->invoicePortion($transaction, $invoice), $reference);

            // "All outstanding": the rest of the charge pays each other invoice it covered,
            // each recorded on its own invoice. Any failure leaves this invoice FAILED, and the
            // retry skips the ones already recorded.
            foreach ((array) $transaction->covered_invoices as $covered) {
                $this->recordOn(
                    $connection, $client, $accountId, $transaction,
                    $this->coveredInvoice($invoice, (array) $covered),
                    round((int) ($covered['amount_cents'] ?? 0) / 100, 2),
                    $reference,
                    covered: true,
                );
            }

            $invoice->forceFill(['pms_sync_status' => 'SYNCED'])->save();

            return true;
        } catch (Throwable $exception) {
            $invoice->forceFill(['pms_sync_status' => 'FAILED'])->save();

            AuditLogger::log('PMS_PAYMENT_RECORD_FAILED', 'invoice', $invoice->id, [
                'pms_source'     => 'lawcus',
                'transaction_id' => $transaction->id,
                'gateway'        => $transaction->gateway,
                'error'          => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Records $portion of the charge as a payment on one Lawcus invoice. Retries (listener
     * re-run, sync command) find the earlier payment by its reference and skip it.
     *
     * A covered invoice that has nothing left to pay by now (e.g. the firm recorded a check
     * meanwhile) means the client paid it twice: that's flagged for the firm to refund rather
     * than failed, since no retry can fix it.
     */
    private function recordOn(
        LawcusConnection $connection,
        Client $client,
        string $accountId,
        Transaction $transaction,
        Invoice $invoice,
        float $portion,
        string $reference,
        bool $covered = false,
    ): void {
        $invoiceUuid = (string) $invoice->external_invoice_id;
        $current     = $this->api->fetchBill($connection, $invoiceUuid);

        if ($this->alreadyRecorded($current, $reference)) {
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
            throw new RuntimeException("Lawcus invoice {$invoice->invoice_number} has nothing left to pay (amount due {$amountDue}).");
        }

        $this->api->recordInvoicePayment($connection, [
            'invoice_id'            => $invoiceUuid,
            'amount'                => $amount,
            'date'                  => ($transaction->created_at ?? now())->copy()->utc()->format('Y-m-d H:i:s'),
            'client_id'             => (int) Arr::get($current, 'data.client_id', $invoice->external_client_id),
            'source_id'             => 'DIRECT',
            'account_id'            => 'DIRECT',
            'destination_id'        => $accountId,
            'source_type'           => $this->sourceType((string) $transaction->gateway),
            'note'                  => $reference,
            // Trust money must land on the matter's trust balance so the firm can later
            // apply it with "use matter trust balance"; without it Lawcus credits the
            // client's trust account with no matter.
            'matter_id'             => $invoice->fund_type === 'TRUST' && is_numeric($invoice->external_matter_id)
                ? (int) $invoice->external_matter_id
                : null,
            'destination_item_id'   => null,
            'destination_item_type' => null,
        ]);

        // Lawcus' create response isn't documented — confirm via the invoice itself.
        if (! $this->alreadyRecorded($this->api->fetchBill($connection, $invoiceUuid), $reference)) {
            throw new RuntimeException("Lawcus accepted the payment request but it does not appear on invoice {$invoice->invoice_number}.");
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
     * The charged amount includes any card surcharge; only the invoice portion is
     * applied in Lawcus (it rejects payments larger than the amount due). An "all
     * outstanding" charge also paid other invoices — their share isn't this invoice's.
     */
    private function invoicePortion(Transaction $transaction, Invoice $invoice): float
    {
        $totalCents = (int) $transaction->amount_cents - $transaction->coveredInvoicesCents();
        $feeCents   = (int) ($transaction->fee_cents ?? 0);

        if ($feeCents === 0 && $totalCents > (int) $invoice->amount_cents) {
            $feeCents = $totalCents - (int) $invoice->amount_cents;
        }

        return round(max(0, $totalCents - $feeCents) / 100, 2);
    }

    private function alreadyRecorded(array $invoicePayload, string $reference): bool
    {
        return collect((array) Arr::get($invoicePayload, 'payments', []))
            ->contains(fn ($payment) => is_array($payment) && str_contains((string) ($payment['note'] ?? ''), $reference));
    }

    /**
     * Written to the Lawcus payment's note — shows staff where the payment came from
     * and doubles as the duplicate-detection key.
     */
    private function reference(Transaction $transaction): string
    {
        return 'Payment Middleware '.strtoupper((string) $transaction->gateway)
            .' txn '.($transaction->gateway_txn_id ?: $transaction->id);
    }

    private function sourceType(string $gateway): string
    {
        return match (strtolower($gateway)) {
            'paya'  => 'ACH',
            default => 'CREDIT_CARD',
        };
    }
}
