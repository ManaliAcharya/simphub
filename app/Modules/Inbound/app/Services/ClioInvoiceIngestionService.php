<?php

namespace Modules\Inbound\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Services\PaymentSessionService;
use Modules\Inbound\Jobs\MarkClioBillSentJob;
use Modules\Inbound\Jobs\SendTrustRequestLinkJob;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClioConnection;
use Modules\Payment\Services\PaymentLinkService;
use RuntimeException;

class ClioInvoiceIngestionService
{
    public function __construct(
        private readonly ClioApiClient $client,
        private readonly ClioOAuthService $oauth,
        private readonly PaymentSessionService $paymentSessions,
        private readonly PaymentLinkService $paymentLinks,
        private readonly InvoiceLinkLifecycleService $lifecycle,
        private readonly MissingRecipientAlertService $missingRecipientAlert,
    ) {}

    public function ingest(string $externalInvoiceId, array $triggerPayload = []): array
    {
        $pmsClientId = $this->resolvePmsClientId($triggerPayload);
        $connection = $this->oauth->ensureValidAccessToken($this->resolveConnection($pmsClientId));
        $invoicePayload = $this->client->fetchBill($connection, $externalInvoiceId);
        $billData = (array) Arr::get($invoicePayload, 'data', $invoicePayload);
        $isTrust = self::isTrustKind($billData)
            ?? $this->client->isTrustBill($connection, $externalInvoiceId);
        $normalized = $this->normalizeInvoice($invoicePayload, $triggerPayload, $isTrust);
        $customerPayload = $this->fetchCustomerPayload($connection, (string) $normalized['external_client_id']);
        $recipientEmails = $this->extractClientEmails($invoicePayload);
        $matterPayload = $this->fetchMatterPayload($connection, $normalized, $pmsClientId);
        $ledesReason = $this->ledesSkipReason($connection, $billData, $matterPayload, $pmsClientId);

        $existing = Invoice::query()
            ->where('pms_source', 'clio')
            ->where('pms_client_id', $pmsClientId)
            ->where('external_invoice_id', $normalized['external_invoice_id'])
            ->first();
        $existedBefore = $existing !== null;
        $resendFingerprintBefore = $existing ? self::resendFingerprint($existing) : null;

        // Once paid through SimpHub the amount is history — Clio's balance then reads 0.
        if ($existing && PaymentSession::query()->where('invoice_id', $existing->id)->where('status', 'COMPLETED')->exists()) {
            $normalized['amount_cents'] = (int) $existing->amount_cents;
        }

        // No backlog: a bill created before the firm connected is never picked up, even
        // when an edit in Clio sends us an "updated" event for it after go-live.
        if (! $existedBefore && self::createdBeforeConnection((array) Arr::get($invoicePayload, 'data', $invoicePayload), $connection)) {
            Log::info('Clio bill created before the firm connected; not ingested.', [
                'pms_client_id'       => $pmsClientId,
                'external_invoice_id' => $normalized['external_invoice_id'],
            ]);

            return ['invoice' => null, 'payment_session' => null, 'emails_sent' => 0, 'skipped' => 'created_before_connection'];
        }

        $result = DB::transaction(function () use ($normalized, $invoicePayload, $customerPayload, $matterPayload, $triggerPayload, $recipientEmails, $pmsClientId, $ledesReason) {
            $invoice = Invoice::query()->updateOrCreate(
                [
                    'pms_source' => 'clio',
                    'pms_client_id' => $pmsClientId,
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
                        'matter'   => $matterPayload,
                        // Insurer-billed (LEDES) matter: no payment email — shown as Skipped.
                        'email_skipped'     => $ledesReason ? ClioBillRules::SKIP_LEDES : null,
                        'email_skip_reason' => $ledesReason,
                    ],
                    'recipient_emails' => $recipientEmails,
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
                $session = $this->paymentSessions->create($invoice);
                $createdSession = true;
            }

            AuditLogger::log('INVOICE_RECEIVED', 'invoice', $invoice->id, [
                'pms_source' => 'clio',
                'pms_client_id' => $pmsClientId,
                'external_invoice_id' => $invoice->external_invoice_id,
                'payment_session_id' => $session->id,
            ]);

            return [
                'invoice' => $invoice->fresh(),
                'payment_session' => $session->fresh(),
                'created_session' => $createdSession,
            ];
        });

        $emailsSent = 0;

        // Trust Request links are held until the firm approves the trust email wording and
        // the client turns sending on; staff can still send one with Resend.
        $trustLinkHeld = $result['invoice']->fund_type === 'TRUST' && ! $this->sendsTrustLinks($pmsClientId);

        if ($trustLinkHeld && $result['created_session']) {
            AuditLogger::log('PAYMENT_LINK_HELD', 'payment_session', $result['payment_session']->id, [
                'invoice_id'    => $result['invoice']->id,
                'pms_client_id' => $pmsClientId,
                'reason'        => 'trust_links_disabled',
            ]);
        }

        $isTrust = $result['invoice']->fund_type === 'TRUST';
        $session = $result['payment_session'];

        // Insurer-billed (LEDES) matter: the firm bills the insurer through its portal, so
        // SimpHub does nothing on approval — for Bills and Trust Requests alike.
        if ($ledesReason !== null && $session->payment_link_sent_at === null
            && $this->isApprovedState($result['invoice']->status) && ! $this->skipAlreadyLogged($session->id)) {
            AuditLogger::log('PAYMENT_LINK_SKIPPED', 'payment_session', $session->id, [
                'invoice_id'    => $result['invoice']->id,
                'pms_client_id' => $pmsClientId,
                'reason'        => 'ledes_matter',
                'detail'        => $ledesReason,
            ]);
        }

        // Paid (e.g. covered by trust funds), voided or deleted in Clio: the link closes now,
        // not only when someone next opens it. Clio sends a void as an "updated" event.
        $closedReason = ClioBillRules::closedReason($billData);

        if ($closedReason !== null && $session->status !== 'COMPLETED') {
            $this->lifecycle->disableOnRemoval('clio', $normalized['external_invoice_id'], $pmsClientId, $closedReason);
        }

        $sendable = $ledesReason === null && $closedReason === null && ! $trustLinkHeld
            && $this->isApprovedState($result['invoice']->status);

        // Ready to go but nobody to send it to: tell the merchant instead of failing silently.
        if ($sendable && $recipientEmails === [] && $session->payment_link_sent_at === null) {
            $this->missingRecipientAlert->alertOnce($result['invoice']);
        }

        // A Trust Request's link waits a moment and goes out only if it's still unpaid — the
        // firm may be recording a check or wire it has already received (SendTrustRequestLinkJob).
        if ($isTrust && $sendable && $recipientEmails !== [] && $session->payment_link_sent_at === null) {
            SendTrustRequestLinkJob::dispatchDelayed($result['invoice']);
        }

        if (! $isTrust && $sendable) {
            // No PDF passed here - PaymentLinkService generates one itself from our
            // own invoice data (same generator used for every PMS), since Clio's own
            // bill-preview API isn't reliably available for this app/account.
            $emailsSent = $this->paymentLinks->sendInvoiceLinkOnce(
                $result['invoice'],
                $result['payment_session'],
                $recipientEmails
            );
        }

        if ($emailsSent > 0) {
            AuditLogger::log('PAYMENT_LINK_SENT', 'payment_session', $result['payment_session']->id, [
                'invoice_id' => $result['invoice']->id,
                'pms_client_id' => $pmsClientId,
                'emails_sent' => $emailsSent,
                'recipient_emails' => $recipientEmails,
            ]);

            MarkClioBillSentJob::dispatchFor($result['invoice']);
        }

        // Clio sends "updated" for every touch on a bill — including our own mark_as_sent right
        // after the first email (MarkClioBillSentJob) — so resend only when something the client
        // sees in the email actually changed. Otherwise every approval emailed the link twice.
        if ($existedBefore && $ledesReason === null && $closedReason === null
            && self::resendFingerprint($result['invoice']) !== $resendFingerprintBefore) {
            $this->lifecycle->resendOnUpdate($result['invoice'], $result['payment_session'], $recipientEmails, $pmsClientId);
        }

        $result['emails_sent'] = $emailsSent;

        return $result;
    }

    /**
     * What the client sees in the payment-link email: amount, currency, bill number, due
     * date and who it goes to. A change to any of these is worth an "Updated" resend.
     *
     * @return array<string, mixed>
     */
    public static function resendFingerprint(Invoice $invoice): array
    {
        $emails = array_map(
            static fn ($email) => strtolower(trim((string) $email)),
            array_filter((array) ($invoice->recipient_emails ?? []), 'is_string')
        );
        sort($emails);

        return [
            'amount_cents'   => (int) $invoice->amount_cents,
            'currency'       => strtoupper((string) $invoice->currency),
            'invoice_number' => (string) $invoice->invoice_number,
            'due_at'         => (string) Arr::get((array) $invoice->raw_payload, 'invoice.data.due_at', ''),
            'recipients'     => array_values(array_unique($emails)),
        ];
    }

    private function skipAlreadyLogged(string $sessionId): bool
    {
        return \Modules\Audit\Models\AuditLog::query()
            ->where('event_type', 'PAYMENT_LINK_SKIPPED')
            ->where('entity_type', 'payment_session')
            ->where('entity_id', $sessionId)
            ->exists();
    }

    /**
     * Why this bill gets no payment email because its matter is billed to an insurer (LEDES):
     * Clio's LEDES setting or the firm's "LEDES" checkbox on any of the bill's matters. The
     * single matter was already fetched; a bill for several matters fetches the others.
     * Best-effort: a matter that can't be read counts as not LEDES, and is logged.
     */
    private function ledesSkipReason(ClioConnection $connection, array $billData, array $matterPayload, string $pmsClientId): ?string
    {
        $matters = [];

        if ($matterPayload !== []) {
            $matters[] = (array) Arr::get($matterPayload, 'data', $matterPayload);
        }

        $fetchedId = (string) Arr::get($matterPayload, 'data.id', '');

        foreach (ClioBillRules::matterIds($billData) as $matterId) {
            if ($matterId === $fetchedId) {
                continue;
            }

            try {
                $matters[] = (array) Arr::get($this->client->fetchMatter($connection, $matterId), 'data', []);
            } catch (\Throwable $e) {
                Log::warning('Clio matter fetch for the LEDES check failed', [
                    'pms_client_id' => $pmsClientId,
                    'matter_id'     => $matterId,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        return ClioBillRules::ledesSkipReason($matters);
    }

    /**
     * Clio bills start in draft/awaiting-approval and only become payable once
     * approved — hold the payment-link email until the bill's state reaches
     * one of the configured approved values (CLIO_APPROVED_BILL_STATES).
     */
    private function isApprovedState(string $status): bool
    {
        return in_array(strtoupper($status), config('services.clio.approved_bill_states', ['APPROVED']), true);
    }

    /**
     * Whether the bill (invoice or Trust Request) was created in Clio before the firm first
     * connected — a reconnect keeps the original connection row, so its created_at is go-live.
     * A bill that didn't return created_at can't be dated and is let through.
     */
    public static function createdBeforeConnection(array $bill, ClioConnection $connection): bool
    {
        $createdAt = trim((string) ($bill['created_at'] ?? ''));

        if ($createdAt === '' || $connection->created_at === null) {
            return false;
        }

        try {
            return CarbonImmutable::parse($createdAt)->lessThan($connection->created_at);
        } catch (\Throwable) {
            return false;
        }
    }

    private function sendsTrustLinks(string $pmsClientId): bool
    {
        return (bool) Client::query()->where('pms_client_id', $pmsClientId)->value('clio_send_trust_links');
    }

    /**
     * Clio Trust Requests are bills whose `kind` is trust (regular invoices are
     * revenue_kind). Null when the bill didn't return its kind, so the caller asks Clio.
     */
    public static function isTrustKind(array $bill): ?bool
    {
        $kind = strtolower(trim((string) ($bill['kind'] ?? '')));

        return $kind === '' ? null : str_contains($kind, 'trust');
    }

    /** The bill's matter when it's for exactly one; a bill for several matters has none. */
    public static function singleMatterId(array $bill): ?string
    {
        $matterIds = collect((array) ($bill['matters'] ?? []))
            ->map(fn ($matter) => is_array($matter) ? (string) ($matter['id'] ?? '') : '')
            ->filter(fn (string $id) => $id !== '')
            ->unique()
            ->values();

        if ($matterIds->count() === 1) {
            return $matterIds->first();
        }

        return Arr::get($bill, 'matter.id') ? (string) Arr::get($bill, 'matter.id') : null;
    }

    private function fetchCustomerPayload(ClioConnection $connection, string $externalClientId): array
    {
        if (trim($externalClientId) === '') {
            return [];
        }

        try {
            return $this->client->fetchContact($connection, $externalClientId);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The bill's matter with its custom field values, which hold the Per-Invoice Fee
     * Override field — for Trust Requests too, which pick between the IOLTA routes. A bill
     * for several matters has no single matter, so the field is then read from the contact.
     *
     * Best-effort: the bill was just fetched with the same token, so a failure here is
     * audited and the field then reads from the contact, else as blank, which follows the
     * merchant's blank default.
     */
    private function fetchMatterPayload(ClioConnection $connection, array $normalized, string $pmsClientId): array
    {
        $matterId = (string) ($normalized['external_matter_id'] ?? '');

        if ($matterId === '') {
            return [];
        }

        try {
            return $this->client->fetchMatter($connection, $matterId);
        } catch (\Throwable $e) {
            Log::warning('Clio matter fetch failed — fee override field read from the contact only', [
                'pms_client_id' => $pmsClientId,
                'matter_id'     => $matterId,
                'error'         => $e->getMessage(),
            ]);

            AuditLogger::log('CLIO_MATTER_FETCH_FAILED', 'client', $pmsClientId, [
                'matter_id'           => $matterId,
                'external_invoice_id' => $normalized['external_invoice_id'],
                'error'               => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * $isTrust comes from Clio's own bill, never the trigger payload — whether money goes to
     * the IOLTA MID and trust account is decided by Clio alone.
     */
    private function normalizeInvoice(array $invoicePayload, array $triggerPayload, bool $isTrust): array
    {
        $data = Arr::get($invoicePayload, 'data', $invoicePayload);
        $id = Arr::get($data, 'id');

        if (! $id) {
            throw new RuntimeException('Clio invoice response did not include an invoice id.');
        }

        // Charge what's still owed (Clio's balance: total less payments and applied trust
        // funds); a bill with nothing owing keeps its total for the record.
        $due   = ClioBillRules::amountDue((array) $data);
        $total = $due > 0 ? $due : Arr::get($data, 'total', Arr::get($data, 'balance', 0));

        return [
            'external_invoice_id' => (string) $id,
            'invoice_number'      => (string) (Arr::get($data, 'number') ?? ''),
            'external_client_id' => (string) (
                Arr::get($data, 'client.id')
                ?? Arr::get($data, 'client.number')
                ?? Arr::get($data, 'client.name')
                ?? Arr::get($data, 'matter.client.id')
                ?? $id
            ),
            'external_matter_id' => self::singleMatterId((array) $data),
            'status' => strtoupper((string) (Arr::get($data, 'state') ?? 'PENDING')),
            'fund_type' => $isTrust ? 'TRUST' : 'OPERATING',
            'amount_cents' => $this->extractAmountInCents($data, $triggerPayload, $total),
            'currency' => $this->extractCurrency($data, $triggerPayload, $total),
        ];
    }

    private function extractAmountInCents(array $data, array $triggerPayload, mixed $primaryAmount): int
    {
        foreach ([
            $primaryAmount,
            Arr::get($data, 'total.amount'),
            Arr::get($data, 'total.value'),
            Arr::get($data, 'total.amount_decimal'),
            Arr::get($data, 'balance.amount'),
            Arr::get($data, 'balance.value'),
            Arr::get($data, 'balance.amount_decimal'),
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
            foreach (['amount_cents', 'cents', 'amount', 'value', 'amount_decimal'] as $key) {
                if (array_key_exists($key, $amount)) {
                    return $this->toCents($amount[$key]);
                }
            }

            return 0;
        }

        if (is_int($amount)) {
            return $amount;
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

    private function extractClientEmails(array $invoicePayload): array
    {
        $data = (array) Arr::get($invoicePayload, 'data', []);
        $emails = [
            Arr::get($data, 'client.email'),
            Arr::get($data, 'client.primary_email_address'),
        ];

        foreach ((array) Arr::get($data, 'client.emails', []) as $email) {
            $emails[] = is_array($email) ? ($email['address'] ?? null) : $email;
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
            throw new RuntimeException('PMS client identifier is required for Clio invoice ingestion.');
        }

        return $pmsClientId;
    }

    private function resolveConnection(string $pmsClientId): ClioConnection
    {
        $connection = ClioConnection::query()
            ->where('provider', 'clio')
            ->where('pms_client_id', $pmsClientId)
            ->first();

        if (! $connection) {
            throw new RuntimeException("Unknown Clio PMS client identifier [{$pmsClientId}].");
        }

        return $connection;
    }
}
