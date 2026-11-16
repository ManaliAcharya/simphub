<?php

namespace Modules\Inbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Models\ClioConnection;
use Modules\Inbound\Services\ClioApiClient;
use Modules\Inbound\Services\ClioOAuthService;
use Throwable;

/**
 * After SimpHub's first payment-link email reaches the client, records it on the Clio
 * bill (mark_as_sent, manual_email) so Clio shows the bill — or Trust Request — as sent
 * and the firm never has to click Send. Best-effort: a failure is audited and never
 * affects the email or the payment link.
 */
class MarkClioBillSentJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    /** Clio adds a new sent event on every call, so a bill is marked once per first send. */
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $invoiceId,
    ) {}

    /** Call right after a first send; does nothing for other PMSs or when turned off. */
    public static function dispatchFor(Invoice $invoice): void
    {
        if ((string) $invoice->pms_source !== 'clio' || ! config('services.clio.mark_bills_sent', true)) {
            return;
        }

        self::dispatch((string) $invoice->id);
    }

    public function uniqueId(): string
    {
        return $this->invoiceId;
    }

    public function handle(ClioApiClient $client, ClioOAuthService $oauth): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);
        $session = $invoice
            ? PaymentSession::query()->where('invoice_id', $invoice->id)->latest('created_at')->first()
            : null;

        if (! $invoice || ! $session) {
            return;
        }

        // Only when the client actually got it: a link routed to the firm's admin alone,
        // or one that failed to deliver, hasn't been sent to the client.
        if (! self::reachedClient((array) ($invoice->recipient_emails ?? []), (array) ($session->payment_link_last_sent_to ?? []))) {
            AuditLogger::log('CLIO_BILL_MARK_SENT_SKIPPED', 'payment_session', $session->id, [
                'invoice_id'          => $invoice->id,
                'external_invoice_id' => $invoice->external_invoice_id,
                'reason'              => 'not_delivered_to_client',
            ]);

            return;
        }

        $connection = ClioConnection::query()
            ->where('provider', 'clio')
            ->where('pms_client_id', $invoice->pms_client_id)
            ->first();

        if (! $connection) {
            return;
        }

        try {
            $response = $client->markBillSent($oauth->ensureValidAccessToken($connection), (string) $invoice->external_invoice_id);

            // 4xx won't succeed on retry (e.g. a voided/deleted bill, or missing permission).
            if ($response->clientError()) {
                $this->recordFailure($invoice, $session, 'HTTP '.$response->status().': '.$response->body());

                return;
            }

            $response->throw();
        } catch (Throwable $e) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff);

                return;
            }

            $this->recordFailure($invoice, $session, $e->getMessage());

            return;
        }

        AuditLogger::log('CLIO_BILL_MARKED_SENT', 'payment_session', $session->id, [
            'invoice_id'          => $invoice->id,
            'pms_client_id'       => $invoice->pms_client_id,
            'external_invoice_id' => $invoice->external_invoice_id,
            'sent_method'         => 'manual_email',
        ]);
    }

    public static function reachedClient(array $clientEmails, array $sentTo): bool
    {
        $normalize = static fn (array $emails) => array_filter(array_map(
            static fn ($email) => is_string($email) ? strtolower(trim($email)) : '',
            $emails
        ));

        return array_intersect($normalize($clientEmails), $normalize($sentTo)) !== [];
    }

    private function recordFailure(Invoice $invoice, PaymentSession $session, string $error): void
    {
        Log::warning('Clio mark_as_sent failed — the bill still shows as not sent in Clio', [
            'invoice_id'          => $invoice->id,
            'pms_client_id'       => $invoice->pms_client_id,
            'external_invoice_id' => $invoice->external_invoice_id,
            'error'               => $error,
        ]);

        AuditLogger::log('CLIO_BILL_MARK_SENT_FAILED', 'payment_session', $session->id, [
            'invoice_id'          => $invoice->id,
            'pms_client_id'       => $invoice->pms_client_id,
            'external_invoice_id' => $invoice->external_invoice_id,
            'error'               => $error,
        ]);
    }
}
