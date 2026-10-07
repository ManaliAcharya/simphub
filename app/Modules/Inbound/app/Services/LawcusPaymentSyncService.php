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

            $accountId = trim((string) $client->lawcus_default_bank_account_id);

            if ($accountId === '') {
                throw new RuntimeException('No default Lawcus bank account configured for this client.');
            }

            $invoiceUuid = (string) $invoice->external_invoice_id;
            $reference   = $this->reference($transaction);
            $current     = $this->api->fetchBill($connection, $invoiceUuid);

            // Retries (listener re-run, sync command) must not record the same payment twice.
            if ($this->alreadyRecorded($current, $reference)) {
                $invoice->forceFill(['pms_sync_status' => 'SYNCED'])->save();

                return true;
            }

            $amountDue = (float) Arr::get($current, 'data.amount_due', 0);
            $amount    = min($this->invoicePortion($transaction, $invoice), $amountDue);

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
                'matter_id'             => null,
                'destination_item_id'   => null,
                'destination_item_type' => null,
            ]);

            // Lawcus' create response isn't documented — confirm via the invoice itself.
            if (! $this->alreadyRecorded($this->api->fetchBill($connection, $invoiceUuid), $reference)) {
                throw new RuntimeException('Lawcus accepted the payment request but it does not appear on the invoice.');
            }

            $invoice->forceFill(['pms_sync_status' => 'SYNCED'])->save();

            AuditLogger::log('PMS_PAYMENT_RECORDED', 'invoice', $invoice->id, [
                'pms_source'          => 'lawcus',
                'transaction_id'      => $transaction->id,
                'gateway'             => $transaction->gateway,
                'gateway_txn_id'      => $transaction->gateway_txn_id,
                'external_invoice_id' => $invoiceUuid,
                'bank_account_id'     => $accountId,
                'amount'              => $amount,
            ]);

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
     * The charged amount includes any card surcharge; only the invoice portion is
     * applied in Lawcus (it rejects payments larger than the amount due).
     */
    private function invoicePortion(Transaction $transaction, Invoice $invoice): float
    {
        $totalCents = (int) $transaction->amount_cents;
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
