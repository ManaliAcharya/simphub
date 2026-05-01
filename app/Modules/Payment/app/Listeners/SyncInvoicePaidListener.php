<?php

namespace Modules\Payment\Listeners;

use Illuminate\Support\Arr;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Services\ZohoApiClient;
use Modules\Inbound\Services\ZohoOAuthService;
use Modules\Payment\Events\PaymentApproved;

class SyncInvoicePaidListener
{
    public function __construct(
        private readonly ZohoOAuthService $zohoOAuth,
        private readonly ZohoApiClient $zohoApi,
    ) {}

    public function handle(PaymentApproved $event): void
    {
        $transaction = Transaction::query()
            ->with('invoice')
            ->find($event->transactionReference);

        if (! $transaction || ! $transaction->invoice) {
            return;
        }

        if (strtolower((string) $transaction->gateway) !== 'paya') {
            return;
        }

        $invoice = $transaction->invoice;
        if (strtolower((string) $invoice->pms_source) !== 'zoho') {
            return;
        }

        $client = Client::query()
            ->where('pms_client_id', $invoice->pms_client_id)
            ->first();

        if (! $client?->call_api_to_pms) {
            return;
        }

        try {
            $connection = PmsConnection::query()
                ->where('provider', 'zoho')
                ->where('pms_client_id', $invoice->pms_client_id)
                ->first();

            $connection = $this->zohoOAuth->ensureValidAccessToken($connection);
            $organizationId = $this->resolveOrganizationId($connection, (array) $invoice->raw_payload);

            $amount = round(((int) $transaction->amount_cents) / 100, 2);

            $this->zohoApi->recordInvoicePayment($connection, $organizationId, [
                'customer_id' => (string) $invoice->external_client_id,
                'payment_mode' => 'Paya',
                'amount' => $amount,
                'date' => now()->toDateString(),
                'reference_number' => (string) $transaction->gateway_txn_id,
                'description' => 'Payment recorded from Payment Middleware checkout',
                'invoices' => [[
                    'invoice_id' => (string) $invoice->external_invoice_id,
                    'amount_applied' => $amount,
                ]],
            ]);

            $invoice->forceFill([
                'pms_sync_status' => 'SYNCED',
            ])->save();

            AuditLogger::log('PMS_PAYMENT_RECORDED', 'invoice', $invoice->id, [
                'pms_source' => 'zoho',
                'transaction_id' => $transaction->id,
                'gateway' => $transaction->gateway,
                'gateway_txn_id' => $transaction->gateway_txn_id,
                'external_invoice_id' => $invoice->external_invoice_id,
                'organization_id' => $organizationId,
            ]);
        } catch (\Throwable $exception) {
            $invoice->forceFill([
                'pms_sync_status' => 'FAILED',
            ])->save();

            AuditLogger::log('PMS_PAYMENT_RECORD_FAILED', 'invoice', $invoice->id, [
                'pms_source' => 'zoho',
                'transaction_id' => $transaction->id,
                'gateway' => $transaction->gateway,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function resolveOrganizationId(PmsConnection $connection, array $rawPayload): string
    {
        $organizationId = (string) (
            Arr::get($rawPayload, 'trigger.organization_id')
            ?? Arr::get($rawPayload, 'invoice.organization_id')
            ?? Arr::get($connection->meta, 'default_organization_id')
            ?? ''
        );

        if ($organizationId === '') {
            throw new \RuntimeException('Zoho organization id is required to record invoice payment.');
        }

        return $organizationId;
    }
}
