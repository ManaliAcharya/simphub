<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Audit\Models\AuditLog;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Services\PaymentSessionService;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\EmailConfiguration;
use Modules\Inbound\Models\JobTreadConnection;
use Modules\Payment\Services\InvoiceReminderPause;
use Modules\Payment\Services\PaymentLinkService;
use RuntimeException;
use Throwable;

/**
 * Turns a JobTread customer invoice into a SimpHub invoice and emails its payment link.
 *
 * Trigger: an invoice reaching "pending" (Push to Pending, or Send). The webhook and the poll
 * only say "look at this document"; the document is always re-read here and the payload never
 * trusted. Each call compares what it reads with the state stored from the last read:
 *
 * - first seen pending              -> record it and email the link
 * - pending again after Draft       -> email again (Make Changes + Push to Pending)
 * - still pending, content changed  -> email again (edited and pushed between two reads)
 * - still pending, content the same -> nothing (e.g. a payment was applied)
 * - Draft                           -> not payable until pushed again; no email
 * - Denied                          -> link closed
 *
 * Deposits and progress billings are separate JobTread invoices, so each is its own SimpHub
 * invoice with its own link.
 */
class JobTreadInvoiceIngestionService
{
    public const SOURCE = 'jobtread';

    public const SENT_BY_JOBTREAD_EVENT = 'JOBTREAD_INVOICE_SENT_BY_JOBTREAD';

    public function __construct(
        private readonly JobTreadApiClient $api,
        private readonly JobTreadConnectionService $connections,
        private readonly PaymentSessionService $paymentSessions,
        private readonly PaymentLinkService $paymentLinks,
        private readonly InvoiceLinkLifecycleService $lifecycle,
        private readonly InvoiceReminderPause $reminders,
        private readonly MissingRecipientAlertService $missingRecipient,
    ) {}

    /**
     * @return array{invoice: ?Invoice, payment_session: ?PaymentSession, emails_sent?: int, skipped?: string}
     */
    public function ingest(string $documentId, array $triggerPayload = []): array
    {
        $pmsClientId = (string) ($triggerPayload['pms_client_id'] ?? '');

        if ($pmsClientId === '') {
            throw new RuntimeException('PMS client identifier is required for JobTread invoice ingestion.');
        }

        $connection = JobTreadConnection::forClient($pmsClientId)
            ?? throw new RuntimeException("Unknown JobTread PMS client identifier [{$pmsClientId}].");

        // The webhook and the poll can see the same change at once; one at a time per document.
        return Cache::lock("jobtread:ingest:{$pmsClientId}:{$documentId}", 120)
            ->block(30, fn () => $this->ingestLocked($connection, $documentId, $triggerPayload));
    }

    private function ingestLocked(JobTreadConnection $connection, string $documentId, array $triggerPayload): array
    {
        $pmsClientId = (string) $connection->pms_client_id;

        try {
            $document = $this->api->fetchDocument($connection, $documentId);
            $this->connections->recordApiSuccess($connection);
        } catch (JobTreadApiException $e) {
            $this->connections->recordApiFailure($connection, $e);

            if ($e->isNotFound()) {
                $this->lifecycle->disableOnRemoval(self::SOURCE, $documentId, $pmsClientId, 'voided');

                return self::skipped('not_found');
            }

            throw $e;
        }

        if (! JobTreadDocument::isCustomerInvoice($document)) {
            return self::skipped('not_customer_invoice');
        }

        if (! JobTreadDocument::passesTestFilter($document)) {
            return self::skipped('outside_test_filter');
        }

        $existing = Invoice::query()
            ->where('pms_source', self::SOURCE)
            ->where('pms_client_id', $pmsClientId)
            ->where('external_invoice_id', (string) $document['id'])
            ->first();

        $previous    = (array) data_get($existing?->raw_payload, 'jobtread_state', []);
        $fingerprint = JobTreadDocument::fingerprint($document);

        if (! $existing) {
            // Never recorded: only an invoice that's pending now, and not one that was already
            // pending, unchanged, when the client connected (no backlog emails).
            if (! JobTreadDocument::isPending($document)) {
                return self::skipped('not_pending');
            }

            $baseline = (array) (((array) ($connection->meta ?? []))['baseline'] ?? []);

            if (($baseline[(string) $document['id']] ?? null) === $fingerprint) {
                return self::skipped('pending_before_connect');
            }
        }

        $state = [
            'status'           => JobTreadDocument::status($document),
            'fingerprint'      => $fingerprint,
            // Content last emailed; a different one on a pending invoice means it was re-pushed.
            'sent_fingerprint' => $previous['sent_fingerprint'] ?? null,
            'read_at'          => now()->toIso8601String(),
            'trigger'          => (string) ($triggerPayload['event_name'] ?? ''),
        ];

        $result = $this->record($existing, $document, $state, $pmsClientId, $triggerPayload);
        $invoice = $result['invoice'];
        $session = $result['payment_session'];

        if (JobTreadDocument::isDenied($document)) {
            $this->lifecycle->disableOnRemoval(self::SOURCE, (string) $document['id'], $pmsClientId, 'voided');

            return $result + ['emails_sent' => 0];
        }

        // Draft (Make Changes), or paid in full: nothing to send. Checkout re-reads the
        // invoice, so a Draft one can't be paid until it's pushed again.
        if (! JobTreadDocument::isPayable($document) || ! $session) {
            return $result + ['emails_sent' => 0];
        }

        $emails  = JobTreadDocument::recipientEmails($document);
        $wasSent = $session->payment_link_sent_at !== null;
        $pushed  = ! $wasSent
            || ($previous['status'] ?? null) !== JobTreadPave::STATUS_PENDING
            || ($previous['sent_fingerprint'] ?? null) !== $fingerprint;

        if (! $pushed) {
            return $result + ['emails_sent' => 0];
        }

        if ($emails === []) {
            $this->missingRecipient->alertOnce($invoice, 'JobTread');
        }

        $this->alertIfSentByJobTread($invoice, $document, $fingerprint);

        $pdf = $this->api->fetchDocumentPdf($connection, (string) $document['id']);

        if (! $wasSent) {
            $emailsSent = $this->paymentLinks->sendInvoiceLinkOnce($invoice, $session, $emails, $pdf);
        } else {
            // Edited (Make Changes) and pushed to pending again: the same link, the new PDF.
            $emailsSent = $this->lifecycle->resendOnUpdate($invoice, $session, $emails, $pmsClientId);

            if ($emailsSent > 0) {
                $this->reminders->restartAfterResend($session);
            }
        }

        if ($emailsSent > 0) {
            $this->saveState($invoice, ['sent_fingerprint' => $fingerprint]);

            AuditLogger::log($wasSent ? 'PAYMENT_LINK_RESENT' : 'PAYMENT_LINK_SENT', 'payment_session', $session->id, [
                'invoice_id'       => $invoice->id,
                'pms_source'       => self::SOURCE,
                'pms_client_id'    => $pmsClientId,
                'emails_sent'      => $emailsSent,
                'recipient_emails' => $emails,
                'jobtread_status'  => $state['status'],
            ]);
        }

        return ['invoice' => $invoice->fresh(), 'payment_session' => $session->fresh(), 'emails_sent' => $emailsSent];
    }

    private function record(?Invoice $existing, array $document, array $state, string $pmsClientId, array $triggerPayload): array
    {
        return DB::transaction(function () use ($existing, $document, $state, $pmsClientId, $triggerPayload) {
            $balance      = JobTreadDocument::balanceCents($document);
            $customerName = JobTreadDocument::customerName($document);
            $emails       = JobTreadDocument::recipientEmails($document);

            // A payment through SimpHub stays PAID here even before JobTread catches up.
            $status = $existing?->status === 'PAID'
                ? 'PAID'
                : strtoupper(JobTreadDocument::status($document) ?: 'PENDING');

            $invoice = Invoice::query()->updateOrCreate(
                [
                    'pms_source'          => self::SOURCE,
                    'pms_client_id'       => $pmsClientId,
                    'external_invoice_id' => (string) $document['id'],
                ],
                [
                    'invoice_number'     => JobTreadDocument::number($document) ?: null,
                    'external_client_id' => (string) (data_get($document, 'job.location.account.id') ?? data_get($document, 'job.id') ?? $document['id']),
                    'external_matter_id' => data_get($document, 'job.id') ? (string) data_get($document, 'job.id') : null,
                    'status'             => $status,
                    'fund_type'          => 'OPERATING',
                    // What's still owed; the payment link always charges the current balance.
                    'amount_cents'       => $balance ?? (int) ($existing?->amount_cents ?? 0),
                    'currency'           => 'USD',
                    'pms_sync_status'    => 'SYNCED',
                    'raw_payload'        => [
                        'trigger'        => array_diff_key($triggerPayload, ['headers' => true]),
                        // "data.client.name" is where shared services look for the customer's name.
                        'invoice'        => ['data' => $document + ['client' => ['name' => $customerName]]],
                        'jobtread_state' => $state,
                    ],
                    'recipient_emails' => $emails,
                    'customer'         => array_filter([
                        'name'  => $customerName,
                        'email' => $emails[0] ?? null,
                    ]),
                    'synced_at'        => now(),
                ]
            );

            Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();

            $session = PaymentSession::query()
                ->where('invoice_id', $invoice->id)
                ->lockForUpdate()
                ->latest('created_at')
                ->first();

            if (! $session && JobTreadDocument::isPayable($document)) {
                $session = $this->paymentSessions->create($invoice);
            }

            if (! $existing) {
                AuditLogger::log('INVOICE_RECEIVED', 'invoice', $invoice->id, [
                    'pms_source'          => self::SOURCE,
                    'pms_client_id'       => $pmsClientId,
                    'external_invoice_id' => $invoice->external_invoice_id,
                    'payment_session_id'  => $session?->id,
                ]);
            }

            return ['invoice' => $invoice->fresh(), 'payment_session' => $session?->fresh()];
        });
    }

    private function saveState(Invoice $invoice, array $changes): void
    {
        $raw                   = (array) $invoice->raw_payload;
        $raw['jobtread_state'] = array_merge((array) ($raw['jobtread_state'] ?? []), $changes);

        $invoice->forceFill(['raw_payload' => $raw])->save();
    }

    /**
     * Staff clicked Send instead of Push to Pending, so JobTread emailed the customer too.
     * The pay link still goes out; the merchant's admin is told once per version of the invoice.
     */
    private function alertIfSentByJobTread(Invoice $invoice, array $document, string $fingerprint): void
    {
        if (! JobTreadDocument::emailedByJobTread($document)) {
            return;
        }

        $alreadyAlerted = AuditLog::query()
            ->where('event_type', self::SENT_BY_JOBTREAD_EVENT)
            ->where('entity_type', 'invoice')
            ->where('entity_id', (string) $invoice->id)
            ->where('payload->fingerprint', $fingerprint)
            ->exists();

        if ($alreadyAlerted) {
            return;
        }

        $client       = Client::query()->where('pms_client_id', $invoice->pms_client_id)->first();
        $emailConfig  = $client ? EmailConfiguration::where('client_id', $client->id)->first() : null;
        $merchantMail = trim((string) ($client?->payment_link_admin_email ?: $emailConfig?->reply_to_email ?: ''));
        $number       = (string) ($invoice->invoice_number ?: $invoice->external_invoice_id);

        AuditLogger::log(self::SENT_BY_JOBTREAD_EVENT, 'invoice', (string) $invoice->id, [
            'pms_client_id' => $invoice->pms_client_id,
            'fingerprint'   => $fingerprint,
            'merchant_email' => $merchantMail ?: null,
        ]);

        if ($merchantMail === '') {
            return;
        }

        $body = "Invoice #{$number} was sent from JobTread with Send, so JobTread emailed it to the customer itself. "
            ."SimpHub has also emailed the customer the invoice with its payment link.\n\n"
            ."To have the customer receive only SimpHub's email, use Push to Pending instead of Send in JobTread.";

        try {
            Mail::raw($body, function ($mail) use ($merchantMail, $number) {
                $mail->to($merchantMail)->subject("Invoice #{$number} was also emailed by JobTread");
            });
        } catch (Throwable $e) {
            Log::error('JobTread sent-by-JobTread alert failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
        }
    }

    private static function skipped(string $reason): array
    {
        return ['invoice' => null, 'payment_session' => null, 'skipped' => $reason, 'emails_sent' => 0];
    }
}
