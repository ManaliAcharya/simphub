<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Audit\Models\AuditLog;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\EmailConfiguration;
use Modules\Inbound\Models\JobTreadConnection;
use Modules\Payment\Listeners\SyncInvoicePaidListener;
use RuntimeException;
use Throwable;

/**
 * Records a captured SimpHub payment on its JobTread invoice:
 * createPayment (externalId = the gateway transaction id), then createDocumentPayment to
 * apply it to the invoice, both with notify: false.
 *
 * The two calls aren't atomic, so every run first looks for what an earlier run already did:
 * a payment on the invoice with our externalId means done; a payment with our externalId
 * that isn't applied yet only needs applying. The result is confirmed by re-reading the
 * invoice; anything unconfirmed goes to the merchant to record by hand, once.
 */
class JobTreadPaymentSyncService
{
    public const ALERT_SENT_EVENT = 'JOBTREAD_RECORD_PAYMENT_ALERT_SENT';

    public function __construct(
        private readonly JobTreadApiClient $api,
        private readonly JobTreadConnectionService $connections,
    ) {}

    /** @return bool true when the payment is (now or already) recorded in JobTread */
    public function sync(Transaction $transaction, Invoice $invoice): bool
    {
        $amount = SyncInvoicePaidListener::invoicePortion($transaction, $invoice);

        try {
            Cache::lock('jobtread:payment:'.$transaction->id, 120)
                ->block(30, fn () => $this->record($transaction, $invoice, $amount));
        } catch (Throwable $exception) {
            AuditLogger::log('PMS_PAYMENT_RECORD_FAILED', 'invoice', $invoice->id, [
                'pms_source'          => 'jobtread',
                'transaction_id'      => $transaction->id,
                'gateway'             => $transaction->gateway,
                'external_invoice_id' => $invoice->external_invoice_id,
                'invoice_number'      => $invoice->invoice_number,
                'error'               => $exception->getMessage(),
            ]);

            if (! $exception instanceof JobTreadPaymentNeedsReview) {
                $this->askMerchantToRecord($transaction, $invoice, $amount);
            }

            $invoice->forceFill(['pms_sync_status' => 'FAILED'])->save();

            return false;
        }

        $invoice->forceFill(['pms_sync_status' => 'SYNCED'])->save();

        return true;
    }

    private function record(Transaction $transaction, Invoice $invoice, float $amount): void
    {
        $connection = JobTreadConnection::forClient((string) $invoice->pms_client_id)
            ?? throw new RuntimeException('JobTread is not connected for this client.');

        $organizationId = $connection->organizationId()
            ?? throw new RuntimeException('JobTread connection has no organization.');

        $documentId = (string) $invoice->external_invoice_id;
        $externalId = self::externalId($transaction);

        try {
            $document = $this->api->fetchDocument($connection, $documentId);
        } catch (Throwable $e) {
            $this->connections->recordApiFailure($connection, $e);

            throw $e;
        }

        if (self::appliedPayment($document, $externalId)) {
            return;
        }

        $balanceBefore = JobTreadDocument::balanceCents($document);

        if ($amount <= 0) {
            throw new JobTreadPaymentNeedsReview('Nothing to record: the invoice portion of this charge is $0.');
        }

        if ($balanceBefore !== null && $balanceBefore <= 0) {
            // Possibly recorded by hand already; asking the merchant to record it could double it.
            throw new JobTreadPaymentNeedsReview("JobTread invoice {$invoice->invoice_number} has nothing left to pay.");
        }

        $amount = $balanceBefore !== null ? min($amount, round($balanceBefore / 100, 2)) : $amount;

        // An earlier run may have created the payment and failed before applying it.
        $paymentId = $this->existingPaymentId($connection, $organizationId, $externalId);

        $writeError = null;

        try {
            if ($paymentId === null) {
                $paymentId = $this->api->createPayment($connection, array_filter([
                    'organizationId' => $organizationId,
                    'amount'         => $amount,
                    'paidAt'         => ($transaction->created_at ?? now())->copy()->utc()->toIso8601String(),
                    'type'           => (string) config('services.jobtread.payment_type', 'credit'),
                    'externalId'     => $externalId,
                    'source'         => config('services.jobtread.payment_source') ?: null,
                ], fn ($value) => $value !== null && $value !== ''));
            }

            $this->api->createDocumentPayment($connection, [
                'documentId' => $documentId,
                'paymentId'  => $paymentId,
                'amount'     => $amount,
            ]);
        } catch (Throwable $e) {
            // An error doesn't prove nothing landed: confirm from the invoice first.
            $writeError = $e;
        }

        if (! $this->confirmRecorded($connection, $documentId, $externalId, $balanceBefore, $amount)) {
            throw new RuntimeException($writeError
                ? "JobTread did not record the payment on invoice {$invoice->invoice_number}: {$writeError->getMessage()}"
                : "JobTread accepted the payment but invoice {$invoice->invoice_number} doesn't show it.");
        }

        $this->connections->recordApiSuccess($connection);

        AuditLogger::log('PMS_PAYMENT_RECORDED', 'invoice', $invoice->id, [
            'pms_source'          => 'jobtread',
            'transaction_id'      => $transaction->id,
            'gateway'             => $transaction->gateway,
            'gateway_txn_id'      => $transaction->gateway_txn_id,
            'external_invoice_id' => $documentId,
            'jobtread_payment_id' => $paymentId,
            'amount'              => $amount,
        ]);
    }

    /** Recorded when the invoice now carries our payment, or its balance dropped by the amount. */
    private function confirmRecorded(JobTreadConnection $connection, string $documentId, string $externalId, ?int $balanceBefore, float $amount): bool
    {
        try {
            $after = $this->api->fetchDocument($connection, $documentId);
        } catch (Throwable) {
            return false;
        }

        if (self::appliedPayment($after, $externalId)) {
            return true;
        }

        $balanceAfter = JobTreadDocument::balanceCents($after);

        return $balanceBefore !== null
            && $balanceAfter !== null
            && $balanceAfter <= $balanceBefore - JobTreadDocument::cents($amount);
    }

    private function existingPaymentId(JobTreadConnection $connection, string $organizationId, string $externalId): ?string
    {
        try {
            $payments = $this->api->paymentsByExternalId($connection, $organizationId, $externalId);
        } catch (Throwable $e) {
            Log::warning('JobTread: lookup of an earlier payment by externalId failed', [
                'external_id' => $externalId,
                'error'       => $e->getMessage(),
            ]);

            return null;
        }

        $id = (string) ($payments[0]['id'] ?? '');

        return $id !== '' ? $id : null;
    }

    public static function appliedPayment(array $document, string $externalId): bool
    {
        return collect((array) Arr::get($document, 'documentPayments.nodes', []))
            ->contains(fn ($node) => is_array($node) && (string) Arr::get($node, 'payment.externalId') === $externalId);
    }

    public static function externalId(Transaction $transaction): string
    {
        return (string) ($transaction->gateway_txn_id ?: $transaction->id);
    }

    private function isAch(Transaction $transaction): bool
    {
        return strtolower((string) $transaction->gateway) === 'paya'
            || strtoupper((string) $transaction->paymentSession?->payment_method) === 'ACH';
    }

    /** Emails the merchant (and ops) the details to record the payment by hand, once per transaction. */
    private function askMerchantToRecord(Transaction $transaction, Invoice $invoice, float $amount): void
    {
        $alreadySent = AuditLog::query()
            ->where('event_type', self::ALERT_SENT_EVENT)
            ->where('payload->transaction_id', $transaction->id)
            ->exists();

        if ($alreadySent) {
            return;
        }

        $client      = Client::query()->where('pms_client_id', $invoice->pms_client_id)->first();
        $replyTo     = $client ? trim((string) (EmailConfiguration::where('client_id', $client->id)->value('reply_to_email') ?? '')) : '';
        $merchant    = $replyTo !== '' ? $replyTo : trim((string) ($client?->payment_link_admin_email ?? ''));
        $recipients  = array_values(array_unique(array_filter([$merchant, trim((string) config('services.jobtread.ops_alert_email'))])));
        $number      = (string) ($invoice->invoice_number ?: $invoice->external_invoice_id);
        $customer    = trim((string) ($invoice->customer['name'] ?? ''));

        if ($recipients === []) {
            Log::warning('JobTread payment not recorded and no merchant or ops email to alert', [
                'transaction_id' => $transaction->id,
                'invoice_number' => $number,
            ]);

            return;
        }

        $body = "We received a payment but couldn't record it in JobTread automatically. Please record it manually:\n\n"
            ."1. In JobTread, open Invoice #{$number}".($customer !== '' ? " ({$customer})" : '').".\n"
            .'2. Add a payment of $'.number_format($amount, 2).' dated '.($transaction->created_at ?? now())->format('m/d/Y').".\n"
            .'3. Reference: SimpHub transaction '.self::externalId($transaction).', '.($this->isAch($transaction) ? 'ACH' : 'card').".\n"
            ."4. Save, and confirm the invoice shows the updated balance.\n\n"
            .'Please record it only once: SimpHub will not try again for this payment.';

        try {
            Mail::raw($body, function ($mail) use ($recipients, $number) {
                $mail->to($recipients)->subject("Action needed: record payment for Invoice #{$number} in JobTread");
            });
        } catch (Throwable $e) {
            Log::error('JobTread record-payment alert failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);

            return;
        }

        AuditLogger::log(self::ALERT_SENT_EVENT, 'invoice', $invoice->id, [
            'transaction_id'      => $transaction->id,
            'external_invoice_id' => (string) $invoice->external_invoice_id,
            'invoice_number'      => $number,
            'amount'              => $amount,
            'recipients'          => $recipients,
        ]);
    }
}
