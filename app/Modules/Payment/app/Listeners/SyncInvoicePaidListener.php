<?php

namespace Modules\Payment\Listeners;

use Illuminate\Support\Arr;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClioConnection;
use Modules\Inbound\Models\LawcusConnection;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Models\QuickBooksConnection;
use Modules\Inbound\Models\WaveConnection;
use Modules\Inbound\Services\ClioApiClient;
use Modules\Inbound\Services\ClioOAuthService;
use Modules\Inbound\Services\LawcusApiClient;
use Modules\Inbound\Services\LawcusOAuthService;
use Modules\Inbound\Services\QuickBooksApiClient;
use Modules\Inbound\Services\QuickBooksOAuthService;
use Modules\Inbound\Services\WaveApiClient;
use Modules\Inbound\Services\WaveOAuthService;
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
        private readonly QuickBooksOAuthService $qbOAuth,
        private readonly QuickBooksApiClient $qbApi,
        private readonly LawcusOAuthService $lawcusOAuth,
        private readonly LawcusApiClient $lawcusApi,
        private readonly WaveOAuthService $waveOAuth,
        private readonly WaveApiClient $waveApi,
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
            'zoho'       => $this->syncToZoho($transaction, $invoice, $client),
            'clio'       => $this->syncToClio($transaction, $invoice, $client),
            'quickbooks' => $this->syncToQuickBooks($transaction, $invoice, $client),
            'lawcus'     => $this->syncToLawcus($transaction, $invoice, $client),
            'wave'       => $this->syncToWave($transaction, $invoice, $client),
            default      => null,
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

    private function syncToClio(Transaction $transaction, mixed $invoice, mixed $client): void
    {
        try {
            $connection = ClioConnection::query()
                ->where('provider', 'clio')
                ->where('pms_client_id', $invoice->pms_client_id)
                ->first();

            $connection = $this->clioOAuth->ensureValidAccessToken($connection);

            $amount        = round(((int) $transaction->amount_cents) / 100, 2);
            $billId        = (string) $invoice->external_invoice_id;
            $date          = now()->toDateString();
            $paymentMethod = $this->clioPaymentType((string) $transaction->gateway);
            $note          = 'Externally processed via Third-Party Processor';

            $bill     = $this->clioApi->fetchBillForSync($connection, $billId);
            $billData = $bill['data'] ?? [];
            $state    = (string) ($billData['state'] ?? '');

            // Clio only allows setting state to awaiting_approval via API (approved requires UI workflow).
            if ($state === 'draft') {
                $this->clioApi->transitionBillState($connection, $billId, 'awaiting_approval');
                $state = 'awaiting_approval';
            }

            $lineItems = collect($this->clioApi->fetchLineItems($connection, $billId))
                ->filter(fn (array $li): bool => (float) ($li['total'] ?? 0) > 0)
                ->values();

            AuditLogger::log('CLIO_BILL_DEBUG', 'invoice', $invoice->id, [
                'bill_id'          => $billId,
                'bill_state_final' => $state,
                'bill_total'       => $billData['total'] ?? null,
                'bill_balance'     => $billData['balance'] ?? null,
                'line_items_count' => $lineItems->count(),
                'line_items'       => $lineItems->all(),
            ]);

            $remaining   = $amount;
            $allocations = [];

            foreach ($lineItems as $lineItem) {
                if ($remaining <= 0) {
                    break;
                }
                $lineBalance   = (float) ($lineItem['total'] ?? 0);
                $allocated     = min($remaining, $lineBalance);
                $remaining     = round($remaining - $allocated, 2);
                $allocations[] = [
                    'line_item_id'   => (int) $lineItem['id'],
                    'amount'         => $allocated,
                    'date'           => $date,
                    'payment_method' => $paymentMethod,
                    'note'           => $note,
                ];
            }

            foreach ($allocations as $allocationPayload) {
                $this->clioApi->recordLineItemPayment($connection, $allocationPayload);
            }

            $invoice->forceFill(['pms_sync_status' => 'SYNCED'])->save();

            AuditLogger::log('PMS_PAYMENT_RECORDED', 'invoice', $invoice->id, [
                'pms_source'          => 'clio',
                'transaction_id'      => $transaction->id,
                'gateway'             => $transaction->gateway,
                'gateway_txn_id'      => $transaction->gateway_txn_id,
                'external_invoice_id' => $invoice->external_invoice_id,
                'line_items_paid'     => count($allocations),
            ]);
        } catch (\Throwable $exception) {
            $invoice->forceFill(['pms_sync_status' => 'FAILED'])->save();

            AuditLogger::log('PMS_PAYMENT_RECORD_FAILED', 'invoice', $invoice->id, [
                'pms_source'     => 'clio',
                'transaction_id' => $transaction->id,
                'gateway'        => $transaction->gateway,
                'error'          => $exception->getMessage(),
            ]);
        }
    }

    private function syncToQuickBooks(Transaction $transaction, mixed $invoice, mixed $client): void
    {
        try {
            $connection = QuickBooksConnection::query()
                ->where('provider', 'quickbooks')
                ->where('pms_client_id', $invoice->pms_client_id)
                ->first();

            $connection = $this->qbOAuth->ensureValidAccessToken($connection);

            $amount = round(((int) $transaction->amount_cents) / 100, 2);

            $payload = [
                'TotalAmt'      => $amount,
                'CustomerRef'   => ['value' => (string) $invoice->external_client_id],
                'TxnDate'       => now()->toDateString(),
                'PaymentRefNum' => (string) $transaction->gateway_txn_id,
                'Line'          => [[
                    'Amount'    => $amount,
                    'LinkedTxn' => [[
                        'TxnId'   => (string) $invoice->external_invoice_id,
                        'TxnType' => 'Invoice',
                    ]],
                ]],
            ];

            if (is_string($client->qb_default_account_id) && trim($client->qb_default_account_id) !== '') {
                $payload['DepositToAccountRef'] = ['value' => $client->qb_default_account_id];
            }

            $this->qbApi->recordPayment($connection, $payload);

            $invoice->forceFill(['pms_sync_status' => 'SYNCED'])->save();

            AuditLogger::log('PMS_PAYMENT_RECORDED', 'invoice', $invoice->id, [
                'pms_source'          => 'quickbooks',
                'transaction_id'      => $transaction->id,
                'gateway'             => $transaction->gateway,
                'gateway_txn_id'      => $transaction->gateway_txn_id,
                'external_invoice_id' => $invoice->external_invoice_id,
                'deposit_account_id'  => $client->qb_default_account_id ?? null,
            ]);
        } catch (\Throwable $exception) {
            $invoice->forceFill(['pms_sync_status' => 'FAILED'])->save();

            AuditLogger::log('PMS_PAYMENT_RECORD_FAILED', 'invoice', $invoice->id, [
                'pms_source'     => 'quickbooks',
                'transaction_id' => $transaction->id,
                'gateway'        => $transaction->gateway,
                'error'          => $exception->getMessage(),
            ]);
        }
    }

    private function syncToLawcus(Transaction $transaction, mixed $invoice, mixed $client): void
    {
        try {
            $connection = LawcusConnection::query()
                ->where('provider', 'lawcus')
                ->where('pms_client_id', $invoice->pms_client_id)
                ->first();

            $connection = $this->lawcusOAuth->ensureValidAccessToken($connection);

            $bankAccountId = is_string($client->lawcus_default_bank_account_id) && trim($client->lawcus_default_bank_account_id) !== ''
                ? (int) $client->lawcus_default_bank_account_id
                : null;

            if ($bankAccountId === null) {
                throw new \RuntimeException('No default Lawcus bank account configured for this client.');
            }

            $amount  = round(((int) $transaction->amount_cents) / 100, 2);
            $billId  = (string) $invoice->external_invoice_id;

            $paymentPayload = [
                'date'          => now()->toDateString(),
                'payment_type'  => $this->lawcusPaymentType((string) $transaction->gateway),
                'reference'     => (string) $transaction->gateway_txn_id,
                'bank_account'  => ['id' => $bankAccountId],
                'bill_payments' => [[
                    'bill'   => ['id' => (int) $billId],
                    'amount' => $amount,
                ]],
            ];

            $this->lawcusApi->recordPayment($connection, $paymentPayload);

            $invoice->forceFill(['pms_sync_status' => 'SYNCED'])->save();

            AuditLogger::log('PMS_PAYMENT_RECORDED', 'invoice', $invoice->id, [
                'pms_source'          => 'lawcus',
                'transaction_id'      => $transaction->id,
                'gateway'             => $transaction->gateway,
                'gateway_txn_id'      => $transaction->gateway_txn_id,
                'external_invoice_id' => $invoice->external_invoice_id,
                'bank_account_id'     => $bankAccountId,
            ]);
        } catch (\Throwable $exception) {
            $invoice->forceFill(['pms_sync_status' => 'FAILED'])->save();

            AuditLogger::log('PMS_PAYMENT_RECORD_FAILED', 'invoice', $invoice->id, [
                'pms_source'     => 'lawcus',
                'transaction_id' => $transaction->id,
                'gateway'        => $transaction->gateway,
                'error'          => $exception->getMessage(),
            ]);
        }
    }

    private function syncToWave(Transaction $transaction, mixed $invoice, mixed $client): void
    {
        try {
            $connection = WaveConnection::query()
                ->where('provider', 'wave')
                ->where('pms_client_id', $invoice->pms_client_id)
                ->latest('created_at')
                ->first();

            if (! $connection) {
                throw new \RuntimeException('No Wave connection found for pms_client_id: ' . $invoice->pms_client_id);
            }

            $connection = $this->waveOAuth->ensureValidAccessToken($connection);

            $amount = round(((int) $transaction->amount_cents) / 100, 2);

            // The invoices listing query stores the Relay global ID in raw_payload.invoice.id
            $invoiceRelayId = (string) Arr::get((array) $invoice->raw_payload, 'invoice.id', '');

            if ($invoiceRelayId === '') {
                $invoiceRelayId = base64_encode('Invoice:' . $invoice->external_invoice_id);
            }

            $clientAccountId = is_string($client->wave_default_account_id) && trim($client->wave_default_account_id) !== ''
                ? $client->wave_default_account_id
                : null;

            $this->waveApi->recordInvoicePayment(
                $connection,
                $invoiceRelayId,
                $amount,
                (string) $transaction->gateway_txn_id,
                now()->toDateString(),
                clientAccountId: $clientAccountId,
                paymentMethod: $this->wavePaymentMethod((string) $transaction->gateway),
            );

            $invoice->forceFill(['pms_sync_status' => 'SYNCED'])->save();

            AuditLogger::log('PMS_PAYMENT_RECORDED', 'invoice', $invoice->id, [
                'pms_source'          => 'wave',
                'transaction_id'      => $transaction->id,
                'gateway'             => $transaction->gateway,
                'gateway_txn_id'      => $transaction->gateway_txn_id,
                'external_invoice_id' => $invoice->external_invoice_id,
                'invoice_relay_id'    => $invoiceRelayId,
            ]);
        } catch (\Throwable $exception) {
            $invoice->forceFill(['pms_sync_status' => 'FAILED'])->save();

            AuditLogger::log('PMS_PAYMENT_RECORD_FAILED', 'invoice', $invoice->id, [
                'pms_source'     => 'wave',
                'transaction_id' => $transaction->id,
                'gateway'        => $transaction->gateway,
                'error'          => $exception->getMessage(),
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

    private function clioPaymentType(string $gateway): string
    {
        return match (strtolower($gateway)) {
            'paya'     => 'Check',
            'fluidpay' => 'Credit Card',
            default    => 'Credit Card',
        };
    }

    private function lawcusPaymentType(string $gateway): string
    {
        return match (strtolower($gateway)) {
            'paya'     => 'Check',
            'fluidpay' => 'Credit Card',
            default    => 'Credit Card',
        };
    }

    private function wavePaymentMethod(string $gateway): string
    {
        return match (strtolower($gateway)) {
            'paya'     => 'ACH_CREDIT_TRANSFER',
            'fluidpay' => 'CREDIT_CARD',
            default    => 'CREDIT_CARD',
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
