<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Services\PaymentSessionService;
use Modules\Inbound\Mail\LawcusConnectionBrokenMail;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\LawcusConnection;
use Modules\Payment\Services\PaymentLinkService;
use RuntimeException;
use Throwable;

class LawcusInvoiceIngestionService
{
    public function __construct(
        private readonly LawcusApiClient $client,
        private readonly LawcusOAuthService $oauth,
        private readonly PaymentSessionService $paymentSessions,
        private readonly PaymentLinkService $paymentLinks,
    ) {}

    public function ingest(string $externalInvoiceId, array $triggerPayload = []): array
    {
        $pmsClientId     = $this->resolvePmsClientId($triggerPayload);
        $connection      = $this->oauth->ensureValidAccessToken($this->resolveConnection($pmsClientId));
        $invoicePayload  = $this->fetchBillTracked($connection, $externalInvoiceId, $pmsClientId);
        $normalized      = $this->normalizeInvoice($invoicePayload, $triggerPayload);
        $customerPayload = $this->fetchCustomerPayload($connection, $invoicePayload, $pmsClientId);
        $recipientEmails = $this->extractClientEmails($invoicePayload, $customerPayload);
        $customerName    = $this->extractClientName($invoicePayload, $customerPayload);

        $result = DB::transaction(function () use ($normalized, $invoicePayload, $customerPayload, $triggerPayload, $recipientEmails, $customerName, $pmsClientId) {
            $invoice = Invoice::query()->updateOrCreate(
                [
                    'pms_source'          => 'lawcus',
                    'pms_client_id'       => $pmsClientId,
                    'external_invoice_id' => $normalized['external_invoice_id'],
                ],
                [
                    'pms_client_id'      => $pmsClientId,
                    'invoice_number'     => $normalized['invoice_number'] ?: null,
                    'external_client_id' => $normalized['external_client_id'],
                    'external_matter_id' => $normalized['external_matter_id'],
                    'status'             => $normalized['status'],
                    'fund_type'          => $normalized['fund_type'],
                    'amount_cents'       => $normalized['amount_cents'],
                    'currency'           => $normalized['currency'],
                    'pms_sync_status'    => 'SYNCED',
                    'raw_payload'        => [
                        'trigger'  => $triggerPayload,
                        'invoice'  => $invoicePayload,
                        'customer' => $customerPayload,
                    ],
                    'recipient_emails' => $recipientEmails,
                    'customer'         => array_filter([
                        'name'  => $customerName,
                        'email' => $recipientEmails[0] ?? null,
                    ]),
                    'synced_at'        => now(),
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

            $createdSession = false;

            if (! $session) {
                $session        = $this->paymentSessions->create($invoice);
                $createdSession = true;
            }

            AuditLogger::log('INVOICE_RECEIVED', 'invoice', $invoice->id, [
                'pms_source'          => 'lawcus',
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

        $emailsSent = $this->paymentLinks->sendInvoiceLinkOnce(
            $result['invoice'],
            $result['payment_session'],
            $recipientEmails
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

    private function fetchBillTracked(LawcusConnection $connection, string $externalInvoiceId, string $pmsClientId): array
    {
        try {
            $payload = $this->client->fetchBill($connection, $externalInvoiceId);
            $this->oauth->recordApiSuccess($connection);

            return $payload;
        } catch (Throwable $e) {
            $this->handleApiFailure($connection, $e, $pmsClientId);

            throw $e;
        }
    }

    /**
     * The REST invoice already embeds the client contact, so the separate contact
     * lookup (by UUID) is only a fallback for when it's missing.
     */
    private function fetchCustomerPayload(LawcusConnection $connection, array $invoicePayload, string $pmsClientId): array
    {
        $embedded = Arr::get($invoicePayload, 'data.client');

        if (is_array($embedded) && $embedded !== []) {
            return $embedded;
        }

        $contactUuid = trim((string) (
            Arr::get($invoicePayload, 'data.client_uuid') ?? Arr::get($invoicePayload, 'data.clientuuid') ?? ''
        ));

        if ($contactUuid === '') {
            return [];
        }

        try {
            $payload = $this->client->fetchContact($connection, $contactUuid);
            $this->oauth->recordApiSuccess($connection);

            return $payload;
        } catch (Throwable $e) {
            $this->handleApiFailure($connection, $e, $pmsClientId);

            return [];
        }
    }

    /**
     * Records the failure against the connection and, the moment it transitions from
     * healthy to broken (not on every subsequent failed attempt), emails ops — Lawcus's
     * per-account access tokens have no automatic refresh, so a revoked/deleted token
     * would otherwise fail silently until someone happened to notice invoices had stopped
     * syncing for this firm.
     */
    private function handleApiFailure(LawcusConnection $connection, Throwable $exception, string $pmsClientId): void
    {
        if (! $this->oauth->recordApiFailure($connection, $exception)) {
            return;
        }

        $opsEmail = config('services.lawcus.ops_alert_email');

        if (! $opsEmail) {
            Log::warning('Lawcus connection broken but LAWCUS_OPS_ALERT_EMAIL is not configured — no alert sent', [
                'pms_client_id' => $pmsClientId,
                'error'         => $exception->getMessage(),
            ]);

            return;
        }

        $client = Client::query()->where('pms_client_id', $pmsClientId)->first();

        if (! $client) {
            return;
        }

        Mail::to($opsEmail)->send(new LawcusConnectionBrokenMail($client, $exception->getMessage()));
    }

    private function normalizeInvoice(array $invoicePayload, array $triggerPayload): array
    {
        $data = Arr::get($invoicePayload, 'data', $invoicePayload);
        $id   = Arr::get($data, 'id');

        if (! $id) {
            throw new RuntimeException('Lawcus invoice response did not include an invoice id.');
        }

        // Bill what's still owed — a partially paid invoice should not be charged in full.
        $total    = Arr::get($data, 'amount_due') ?? Arr::get($data, 'total', Arr::get($data, 'balance', 0));
        $fundType = strtoupper((string) (
            Arr::get($data, 'fund_type')
            ?? Arr::get($triggerPayload, 'fund_type')
            ?? 'OPERATING'
        ));

        return [
            'external_invoice_id' => (string) $id,
            'invoice_number'      => (string) (Arr::get($data, 'number') ?? Arr::get($data, 'invoice_number') ?? ''),
            'external_client_id'  => (string) (
                Arr::get($data, 'client.id')
                ?? Arr::get($data, 'client.number')
                ?? Arr::get($data, 'client.name')
                ?? Arr::get($data, 'contact.id')
                ?? $id
            ),
            'external_matter_id' => Arr::get($data, 'matter.id')
                ? (string) Arr::get($data, 'matter.id')
                : null,
            'status'      => strtoupper((string) (Arr::get($data, 'status') ?? Arr::get($data, 'state') ?? 'PENDING')),
            'fund_type'   => in_array($fundType, ['TRUST', 'OPERATING'], true) ? $fundType : 'OPERATING',
            'amount_cents' => $this->extractAmountInCents($data, $triggerPayload, $total),
            'currency'     => $this->extractCurrency($data, $triggerPayload, $total),
        ];
    }

    private function extractAmountInCents(array $data, array $triggerPayload, mixed $primaryAmount): int
    {
        foreach ([
            $primaryAmount,
            Arr::get($data, 'total.amount'),
            Arr::get($data, 'total.value'),
            Arr::get($data, 'balance.amount'),
            Arr::get($data, 'balance.value'),
            Arr::get($triggerPayload, 'total'),
            Arr::get($triggerPayload, 'amount'),
            Arr::get($triggerPayload, 'amount_cents'),
        ] as $candidate) {
            $cents = $this->toCents($candidate);

            if ($cents > 0) {
                return $cents;
            }
        }

        return 0;
    }

    private function toCents(mixed $amount): int
    {
        if (is_array($amount)) {
            foreach (['amount_cents', 'cents', 'amount', 'value'] as $key) {
                if (array_key_exists($key, $amount)) {
                    return $this->toCents($amount[$key]);
                }
            }

            return 0;
        }

        if (is_numeric($amount)) {
            return (int) round(((float) $amount) * 100);
        }

        return 0;
    }

    private function extractCurrency(array $data, array $triggerPayload, mixed $primaryAmount): string
    {
        foreach ([
            Arr::get($data, 'currency'),
            Arr::get($data, 'total.currency'),
            Arr::get($data, 'balance.currency'),
            is_array($primaryAmount) ? ($primaryAmount['currency'] ?? null) : null,
            Arr::get($triggerPayload, 'currency'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return strtoupper(trim($candidate));
            }
        }

        return 'USD';
    }

    private function extractClientEmails(array $invoicePayload, array $customerPayload = []): array
    {
        $data   = (array) Arr::get($invoicePayload, 'data', []);
        $emails = [
            Arr::get($data, 'client_email'),
            Arr::get($customerPayload, 'email'),
            Arr::get($data, 'client.email'),
            Arr::get($data, 'client.primary_email_address'),
            Arr::get($data, 'contact.email'),
            Arr::get($data, 'billing_contact.email'),
        ];

        // Lawcus returns contact "emails" as a JSON-encoded string of {type, value, is_primary}.
        foreach ([Arr::get($data, 'client.emails'), Arr::get($customerPayload, 'emails')] as $list) {
            if (is_string($list)) {
                $list = json_decode($list, true);
            }

            foreach ((array) $list as $email) {
                $emails[] = is_array($email) ? ($email['value'] ?? $email['address'] ?? null) : $email;
            }
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($email) => is_string($email) ? trim($email) : null,
            $emails
        ))));
    }

    private function extractClientName(array $invoicePayload, array $customerPayload): ?string
    {
        $data = (array) Arr::get($invoicePayload, 'data', []);

        $name = trim((string) (
            Arr::get($customerPayload, 'name')
            ?? Arr::get($data, 'client_name')
            ?? trim(Arr::get($data, 'client_first_name', '').' '.Arr::get($data, 'client_last_name', ''))
        ));

        return $name !== '' ? $name : null;
    }

    private function resolvePmsClientId(array $triggerPayload): string
    {
        $pmsClientId = (string) (
            $triggerPayload['pms_client_id']
            ?? data_get($triggerPayload, 'meta.pms_client_id')
            ?? ''
        );

        if ($pmsClientId === '') {
            throw new RuntimeException('PMS client identifier is required for Lawcus invoice ingestion.');
        }

        return $pmsClientId;
    }

    private function resolveConnection(string $pmsClientId): LawcusConnection
    {
        $connection = LawcusConnection::query()
            ->where('provider', 'lawcus')
            ->where('pms_client_id', $pmsClientId)
            ->first();

        if (! $connection) {
            throw new RuntimeException("Unknown Lawcus PMS client identifier [{$pmsClientId}].");
        }

        return $connection;
    }
}
