<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Services\PaymentSessionService;
use Modules\Inbound\Models\PmsConnection;
use Modules\Payment\Services\PaymentLinkService;
use RuntimeException;

class ZohoInvoiceIngestionService
{
    public function __construct(
        private readonly ZohoApiClient $client,
        private readonly ZohoOAuthService $oauth,
        private readonly PaymentSessionService $paymentSessions,
        private readonly PaymentLinkService $paymentLinks,
    ) {}

    public function ingest(string $externalInvoiceId, array $triggerPayload = []): array
    {
        $pmsClientId = $this->resolvePmsClientId($triggerPayload);
        $connection = $this->oauth->ensureValidAccessToken($this->resolveConnection($pmsClientId));
        $organizationId = $this->resolveOrganizationId($connection, $triggerPayload);
        $invoicePayload = $this->client->fetchBill($connection, $externalInvoiceId, $organizationId);
        $normalized = $this->normalizeInvoice($invoicePayload, $triggerPayload, $organizationId);
        $recipientEmails = $this->extractClientEmails($invoicePayload, $triggerPayload);

        $result = DB::transaction(function () use ($normalized, $invoicePayload, $triggerPayload, $recipientEmails, $pmsClientId) {
            $invoice = Invoice::query()->updateOrCreate(
                [
                    'pms_source' => 'zoho',
                    'pms_client_id' => $pmsClientId,
                    'external_invoice_id' => $normalized['external_invoice_id'],
                ],
                [
                    'pms_client_id' => $pmsClientId,
                    'external_client_id' => $normalized['external_client_id'],
                    'external_matter_id' => $normalized['external_matter_id'],
                    'status' => $normalized['status'],
                    'fund_type' => $normalized['fund_type'],
                    'amount_cents' => $normalized['amount_cents'],
                    'currency' => $normalized['currency'],
                    'pms_sync_status' => 'SYNCED',
                    'raw_payload' => [
                        'trigger' => $triggerPayload,
                        'invoice' => $invoicePayload,
                    ],
                    'recipient_emails' => $recipientEmails,
                    'synced_at' => now(),
                ]
            );

            Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->first();

            $session = PaymentSession::query()
                ->where('invoice_id', $invoice->id)
                ->lockForUpdate()
                ->latest('created_at')
                ->first();

            if (! $session) {
                $session = $this->paymentSessions->create($invoice);
            }

            AuditLogger::log('INVOICE_RECEIVED', 'invoice', $invoice->id, [
                'pms_source' => 'zoho',
                'pms_client_id' => $pmsClientId,
                'external_invoice_id' => $invoice->external_invoice_id,
                'payment_session_id' => $session->id,
            ]);

            return [
                'invoice' => $invoice->fresh(),
                'payment_session' => $session->fresh(),
            ];
        });

        $emailsSent = $this->paymentLinks->sendInvoiceLinkOnce(
            $result['invoice'],
            $result['payment_session'],
            $recipientEmails
        );

        if ($emailsSent > 0) {
            AuditLogger::log('PAYMENT_LINK_SENT', 'payment_session', $result['payment_session']->id, [
                'invoice_id' => $result['invoice']->id,
                'pms_client_id' => $pmsClientId,
                'emails_sent' => $emailsSent,
                'recipient_emails' => $recipientEmails,
            ]);
        }

        $result['emails_sent'] = $emailsSent;

        return $result;
    }

    private function normalizeInvoice(array $invoicePayload, array $triggerPayload, string $organizationId): array
    {
        $data = Arr::get($invoicePayload, 'bill', $invoicePayload);
        $billId = Arr::get($data, 'bill_id');

        if (! $billId) {
            throw new RuntimeException('Zoho bill response did not include a bill id.');
        }

        $fundType = strtoupper((string) (
            Arr::get($data, 'fund_type')
            ?? Arr::get($triggerPayload, 'fund_type')
            ?? 'OPERATING'
        ));

        return [
            'external_invoice_id' => (string) $billId,
            'external_client_id' => (string) (
                Arr::get($data, 'vendor_id')
                ?? Arr::get($triggerPayload, 'vendor_id')
                ?? Arr::get($data, 'vendor_name')
                ?? $billId
            ),
            'external_matter_id' => (string) (
                Arr::get($data, 'reference_number')
                ?? Arr::get($triggerPayload, 'reference_number')
                ?? $organizationId
            ),
            'status' => strtoupper((string) (Arr::get($data, 'status') ?? 'OPEN')),
            'fund_type' => in_array($fundType, ['TRUST', 'OPERATING'], true) ? $fundType : 'OPERATING',
            'amount_cents' => $this->toCents(
                Arr::get($data, 'total', Arr::get($data, 'balance', Arr::get($triggerPayload, 'amount', 0)))
            ),
            'currency' => strtoupper((string) (
                Arr::get($data, 'currency_code')
                ?? Arr::get($triggerPayload, 'currency')
                ?? 'USD'
            )),
        ];
    }

    private function extractClientEmails(array $invoicePayload, array $triggerPayload): array
    {
        $data = Arr::get($invoicePayload, 'bill', []);
        $emails = [
            Arr::get($data, 'vendor_email'),
            Arr::get($data, 'billing_address.email'),
            Arr::get($triggerPayload, 'vendor_email'),
            Arr::get($triggerPayload, 'billing_address.email'),
            Arr::get($triggerPayload, 'contact.email'),
        ];

        foreach ((array) Arr::get($data, 'contact_persons', []) as $contact) {
            $emails[] = Arr::get($contact, 'email');
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($email) => is_string($email) ? trim($email) : null,
            $emails
        ))));
    }

    private function resolvePmsClientId(array $triggerPayload): string
    {
        $pmsClientId = (string) (
            $triggerPayload['pms_client_id']
            ?? data_get($triggerPayload, 'meta.pms_client_id')
            ?? ''
        );

        if ($pmsClientId === '') {
            throw new RuntimeException('PMS client identifier is required for Zoho invoice ingestion.');
        }

        return $pmsClientId;
    }

    private function resolveOrganizationId(PmsConnection $connection, array $triggerPayload): string
    {
        $organizationId = (string) (
            $triggerPayload['organization_id']
            ?? data_get($triggerPayload, 'organization.organization_id')
            ?? data_get($triggerPayload, 'data.organization_id')
            ?? data_get($connection->meta, 'organizations_payload.org.0.id')
            ?? ''
        );

        if ($organizationId === '') {
            throw new RuntimeException('Zoho organization id is required to fetch bill details.');
        }

        return $organizationId;
    }

    private function resolveConnection(string $pmsClientId): PmsConnection
    {
        $connection = PmsConnection::query()
            ->where('provider', 'zoho')
            ->where('pms_client_id', $pmsClientId)
            ->first();

        if (! $connection) {
            throw new RuntimeException("Unknown Zoho PMS client identifier [{$pmsClientId}].");
        }

        return $connection;
    }

    private function toCents(mixed $amount): int
    {
        if (is_array($amount)) {
            foreach (['amount_cents', 'cents', 'amount', 'value', 'amount_decimal'] as $key) {
                if (array_key_exists($key, $amount)) {
                    return $this->toCents($amount[$key]);
                }
            }

            return 0;
        }

        if (is_int($amount)) {
            return (int) round($amount * 100);
        }

        if (is_numeric($amount)) {
            return (int) round(((float) $amount) * 100);
        }

        return 0;
    }
}
