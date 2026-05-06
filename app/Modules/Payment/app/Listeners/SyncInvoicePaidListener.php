<?php

namespace Modules\Payment\Listeners;

use Illuminate\Support\Arr;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClioConnection;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Services\ClioApiClient;
use Modules\Inbound\Services\ClioOAuthService;
use Modules\Inbound\Services\ZohoApiClient;
use Modules\Inbound\Services\ZohoOAuthService;
use Modules\Payment\Events\PaymentApproved;

class SyncInvoicePaidListener
{
    public function __construct(
        private readonly ZohoOAuthService $zohoOAuth,
        private readonly ZohoApiClient $zohoApi,
        private readonly ClioOAuthService $clioOAuth,
        private readonly ClioApiClient $clioApi,
    ) {}

    public function handle(PaymentApproved $event): void
    {
        $transaction = Transaction::query()
            ->with('invoice')
            ->find($event->transactionReference);

        if (! $transaction || ! $transaction->invoice) {
            return;
        }

        $invoice = $transaction->invoice;

        $client = Client::query()
            ->where('pms_client_id', $invoice->pms_client_id)
            ->first();

        if (! $client?->call_api_to_pms) {
            return;
        }

        match (strtolower((string) $invoice->pms_source)) {
            'zoho' => $this->syncToZoho($transaction, $invoice, $client),
            'clio' => $this->syncToClio($transaction, $invoice),
            default => null,
        };
    }

    private function syncToZoho(Transaction $transaction, mixed $invoice, mixed $client): void
    {
        try {
            $connection = PmsConnection::query()
                ->where('provider', 'zoho')
                ->where('pms_client_id', $invoice->pms_client_id)
                ->first();

            $connection = $this->zohoOAuth->ensureValidAccessToken($connection);
            $organizationId = $this->resolveOrganizationId($connection, (array) $invoice->raw_payload);

            $amount = round(((int) $transaction->amount_cents) / 100, 2);

            $payload = [
                'customer_id' => (string) $invoice->external_client_id,
                'payment_mode' => $this->zohoPaymentMode((string) $transaction->gateway),
                'amount' => $amount,
                'date' => now()->toDateString(),
                'reference_number' => (string) $transaction->gateway_txn_id,
                'description' => 'Payment recorded from Payment Middleware checkout',
                'invoices' => [[
                    'invoice_id' => (string) $invoice->external_invoice_id,
                    'amount_applied' => $amount,
                ]],
            ];

            if (is_string($client->zoho_default_account_id) && trim($client->zoho_default_account_id) !== '') {
                $payload['account_id'] = $client->zoho_default_account_id;
            }

            $this->zohoApi->recordInvoicePayment($connection, $organizationId, $payload);

            $invoice->forceFill(['pms_sync_status' => 'SYNCED'])->save();

            AuditLogger::log('PMS_PAYMENT_RECORDED', 'invoice', $invoice->id, [
                'pms_source' => 'zoho',
                'transaction_id' => $transaction->id,
                'gateway' => $transaction->gateway,
                'gateway_txn_id' => $transaction->gateway_txn_id,
                'external_invoice_id' => $invoice->external_invoice_id,
                'organization_id' => $organizationId,
            ]);
        } catch (\Throwable $exception) {
            $invoice->forceFill(['pms_sync_status' => 'FAILED'])->save();

            AuditLogger::log('PMS_PAYMENT_RECORD_FAILED', 'invoice', $invoice->id, [
                'pms_source' => 'zoho',
                'transaction_id' => $transaction->id,
                'gateway' => $transaction->gateway,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function syncToClio(Transaction $transaction, mixed $invoice): void
    {
        try {
            $connection = ClioConnection::query()
                ->where('provider', 'clio')
                ->where('pms_client_id', $invoice->pms_client_id)
                ->first();

            $connection = $this->clioOAuth->ensureValidAccessToken($connection);

            $amount = round(((int) $transaction->amount_cents) / 100, 2);

            $this->clioApi->recordPayment($connection, [
                'date' => now()->toDateString(),
                'amount' => $amount,
                'bill' => ['id' => (int) $invoice->external_invoice_id],
                'source' => $this->clioPaymentSource((string) $transaction->gateway),
                'reference_no' => (string) $transaction->gateway_txn_id,
                'note' => 'Payment recorded from Payment Middleware checkout',
            ]);

            $invoice->forceFill(['pms_sync_status' => 'SYNCED'])->save();

            AuditLogger::log('PMS_PAYMENT_RECORDED', 'invoice', $invoice->id, [
                'pms_source' => 'clio',
                'transaction_id' => $transaction->id,
                'gateway' => $transaction->gateway,
                'gateway_txn_id' => $transaction->gateway_txn_id,
                'external_invoice_id' => $invoice->external_invoice_id,
            ]);
        } catch (\Throwable $exception) {
            $invoice->forceFill(['pms_sync_status' => 'FAILED'])->save();

            AuditLogger::log('PMS_PAYMENT_RECORD_FAILED', 'invoice', $invoice->id, [
                'pms_source' => 'clio',
                'transaction_id' => $transaction->id,
                'gateway' => $transaction->gateway,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function zohoPaymentMode(string $gateway): string
    {
        return match (strtolower($gateway)) {
            'paya' => 'Paya',
            'fluidpay' => 'Credit Card',
            default => 'Credit Card',
        };
    }

    private function clioPaymentSource(string $gateway): string
    {
        return match (strtolower($gateway)) {
            'paya' => 'bank_transfer',
            'fluidpay' => 'credit_card',
            default => 'credit_card',
        };
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
