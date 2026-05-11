<?php

namespace Modules\Payment\Listeners;

use Illuminate\Support\Arr;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClioConnection;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Models\QuickBooksConnection;
use Modules\Inbound\Services\ClioApiClient;
use Modules\Inbound\Services\ClioOAuthService;
use Modules\Inbound\Services\QuickBooksApiClient;
use Modules\Inbound\Services\QuickBooksOAuthService;
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

            $bankAccountId = is_string($client->clio_default_bank_account_id) && trim($client->clio_default_bank_account_id) !== ''
                ? (int) $client->clio_default_bank_account_id
                : null;

            if ($bankAccountId === null) {
                throw new \RuntimeException('No default Clio bank account configured for this client.');
            }

            $amount = round(((int) $transaction->amount_cents) / 100, 2);

            $paymentRecorded = false;
            $billId          = (string) $invoice->external_invoice_id;

            $paymentPayload = [
                'date'          => now()->toDateString(),
                'payment_type'  => $this->clioPaymentType((string) $transaction->gateway),
                'reference'     => (string) $transaction->gateway_txn_id,
                'bank_account'  => ['id' => $bankAccountId],
                'bill_payments' => [[
                    'bill'   => ['id' => (int) $billId],
                    'amount' => $amount,
                ]],
            ];

            try {
                $this->clioApi->recordPayment($connection, $paymentPayload);
                $paymentRecorded = true;
            } catch (\Illuminate\Http\Client\RequestException $e) {
                $status = $e->response->status();

                // if ($this->isClioDraftTransitionError($e)) {
                //     // Bill is in Draft — promote it to Outstanding first, then retry.
                //     $this->clioApi->transitionBillToOutstanding($connection, $billId);
                //
                //     try {
                //         $this->clioApi->recordPayment($connection, $paymentPayload);
                //         $paymentRecorded = true;
                //     } catch (\Illuminate\Http\Client\RequestException $retryException) {
                //         $retryStatus = $retryException->response->status();
                //         if ($retryStatus !== 401 && $retryStatus !== 403) {
                //             throw $retryException;
                //         }
                //         $this->clioApi->markBillPaid($connection, $billId);
                //     }
                // } elseif ($status === 401 || $status === 403) {
                if ($status === 401 || $status === 403) {
                    // Account lacks payment-recording permission — fall back to state PATCH.
                    // If the bill somehow ended up in Draft via a different path, handle it here too.
                    try {
                        $this->clioApi->markBillPaid($connection, $billId);
                    } catch (\Illuminate\Http\Client\RequestException $patchException) {
                        // if ($this->isClioDraftTransitionError($patchException)) {
                        //     $this->clioApi->transitionBillToOutstanding($connection, $billId);
                        //     $this->clioApi->markBillPaid($connection, $billId);
                        // } else {
                            throw $patchException;
                        // }
                    }
                } else {
                    throw $e;
                }
            }

            $invoice->forceFill(['pms_sync_status' => 'SYNCED'])->save();

            AuditLogger::log('PMS_PAYMENT_RECORDED', 'invoice', $invoice->id, [
                'pms_source'          => 'clio',
                'transaction_id'      => $transaction->id,
                'gateway'             => $transaction->gateway,
                'gateway_txn_id'      => $transaction->gateway_txn_id,
                'external_invoice_id' => $invoice->external_invoice_id,
                'bank_account_id'     => $bankAccountId,
                'method'              => $paymentRecorded ? 'payment_record' : 'bill_state_patch',
                'note'                => $paymentRecorded ? null : 'Payment record creation returned 401/403 — bill marked paid via PATCH. Check: connected user must be Administrator and Clio Payments must be active on the account.',
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

    private function isClioDraftTransitionError(\Illuminate\Http\Client\RequestException $e): bool
    {
        $body = strtolower($e->response->body());

        return str_contains($body, 'draft') && str_contains($body, 'paid');
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
