<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Services\PaymentSessionService;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\QuickBooksConnection;
use Modules\Payment\Services\PaymentLinkService;
use RuntimeException;

class QuickBooksInvoiceIngestionService
{
    public function __construct(
        private readonly QuickBooksApiClient $client,
        private readonly QuickBooksOAuthService $oauth,
        private readonly PaymentSessionService $paymentSessions,
        private readonly PaymentLinkService $paymentLinks,
        private readonly QuickBooksEmailResolver $emailResolver,
    ) {}

    public function ingest(string $externalInvoiceId, array $triggerPayload = []): array
    {
        $pmsClientId = $this->resolvePmsClientId($triggerPayload);
        $connection  = $this->oauth->ensureValidAccessToken($this->resolveConnection($pmsClientId));

        $invoicePayload  = $this->client->fetchInvoice($connection, $externalInvoiceId);
        $normalized      = $this->normalizeInvoice($invoicePayload, $triggerPayload);
        $resolvedEmails  = $this->emailResolver->resolve($connection, $invoicePayload);
        $customerPayload = $resolvedEmails['customer_payload'];
        $recipientEmails = $resolvedEmails['emails'];

        $result = DB::transaction(function () use ($normalized, $invoicePayload, $customerPayload, $triggerPayload, $recipientEmails, $pmsClientId) {
            $invoice = Invoice::query()->updateOrCreate(
                [
                    'pms_source'          => 'quickbooks',
                    'pms_client_id'       => $pmsClientId,
                    'external_invoice_id' => $normalized['external_invoice_id'],
                ],
                [
                    'pms_client_id'      => $pmsClientId,
                    'invoice_number'     => $normalized['invoice_number'] ?: null,
                    'external_client_id' => $normalized['external_client_id'],
                    'status'             => $normalized['status'],
                    'fund_type'          => 'OPERATING',
                    'amount_cents'       => $normalized['amount_cents'],
                    'currency'           => $normalized['currency'],
                    'pms_sync_status'    => 'SYNCED',
                    'raw_payload'        => [
                        'trigger'  => $triggerPayload,
                        'invoice'  => $invoicePayload,
                        'customer' => $customerPayload,
                    ],
                    'recipient_emails'   => $recipientEmails,
                    'synced_at'          => now(),
                ]
            );

            Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();

            $session = PaymentSession::query()
                ->where('invoice_id', $invoice->id)
                ->lockForUpdate()
                ->latest('created_at')
                ->first();

            $createdSession = false;

            if (! $session) {
                $session        = $this->paymentSessions->create($invoice);
                $createdSession = true;
            }

            AuditLogger::log('INVOICE_RECEIVED', 'invoice', $invoice->id, [
                'pms_source'          => 'quickbooks',
                'pms_client_id'       => $pmsClientId,
                'external_invoice_id' => $invoice->external_invoice_id,
                'payment_session_id'  => $session->id,
            ]);

            return [
                'invoice'         => $invoice->fresh(),
                'payment_session' => $session->fresh(),
                'created_session' => $createdSession,
            ];
        });

        // Clients can opt out of QuickBooks' own PDF export in favor of the PDF we
        // generate ourselves from our invoice data (PaymentLinkService falls back to
        // it automatically whenever $pdf is null) - default stays QB's native export.
        $useNativePdf = Client::query()->where('pms_client_id', $pmsClientId)->value('qb_use_native_pdf') ?? true;
        $pdf = $useNativePdf ? $this->client->fetchInvoicePdf($connection, $externalInvoiceId) : null;

        $emailsSent = $this->paymentLinks->sendInvoiceLinkOnce(
            $result['invoice'],
            $result['payment_session'],
            $recipientEmails,
            $pdf
        );

        if ($emailsSent > 0) {
            AuditLogger::log('PAYMENT_LINK_SENT', 'payment_session', $result['payment_session']->id, [
                'invoice_id'       => $result['invoice']->id,
                'pms_client_id'    => $pmsClientId,
                'emails_sent'      => $emailsSent,
                'recipient_emails' => $recipientEmails,
            ]);
        }

        $result['emails_sent'] = $emailsSent;

        return $result;
    }

    private function normalizeInvoice(array $invoicePayload, array $triggerPayload): array
    {
        // QB wraps the invoice under the key "Invoice"
        $data = Arr::get($invoicePayload, 'Invoice', $invoicePayload);

        $id = (string) ($data['Id'] ?? '');

        if ($id === '') {
            throw new RuntimeException('QuickBooks invoice response did not include an Id.');
        }

        $customerId = (string) (
            Arr::get($data, 'CustomerRef.value')
            ?? Arr::get($data, 'CustomerRef.name')
            ?? ''
        );

        $balance = (float) ($data['Balance'] ?? $data['TotalAmt'] ?? 0);
        $total   = (float) ($data['TotalAmt'] ?? 0);
        $amount  = $balance > 0 ? $balance : $total;

        $statusRaw = strtolower((string) ($data['EmailStatus'] ?? ''));
        $status    = match ($statusRaw) {
            'emailsent' => 'SENT',
            'notset'    => 'PENDING',
            default     => 'PENDING',
        };

        if ((float) ($data['Balance'] ?? 1) === 0.0) {
            $status = 'PAID';
        }

        return [
            'external_invoice_id' => $id,
            'invoice_number'      => (string) ($data['DocNumber'] ?? ''),
            'external_client_id'  => $customerId,
            'status'              => $status,
            'amount_cents'        => (int) round($amount * 100),
            'currency'            => strtoupper((string) (Arr::get($data, 'CurrencyRef.value') ?? 'USD')),
        ];
    }

    private function resolvePmsClientId(array $triggerPayload): string
    {
        $pmsClientId = (string) ($triggerPayload['pms_client_id'] ?? '');

        if ($pmsClientId === '') {
            throw new RuntimeException('PMS client identifier is required for QuickBooks invoice ingestion.');
        }

        return $pmsClientId;
    }

    private function resolveConnection(string $pmsClientId): QuickBooksConnection
    {
        $connection = QuickBooksConnection::query()
            ->where('provider', 'quickbooks')
            ->where('pms_client_id', $pmsClientId)
            ->first();

        if (! $connection) {
            throw new RuntimeException("No QuickBooks connection found for PMS client [{$pmsClientId}].");
        }

        return $connection;
    }
}
