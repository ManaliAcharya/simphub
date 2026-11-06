<?php

namespace Modules\Inbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Services\LawcusMatterActivityService;
use Modules\Payment\Services\InvoiceLiveStatusResolver;
use Modules\Payment\Services\PaymentLinkService;

/**
 * Emails a new Lawcus/Clio Trust Request's payment link a short while after it's created,
 * and only if it's still unpaid then. A firm that's already received the money by check or
 * wire creates the Trust Request and records the payment right away; the delay gives that
 * payment time to land, so the client isn't asked to pay twice.
 */
class SendTrustRequestLinkJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const DELAY_SECONDS = 30;

    public int $tries = 3;

    public int $backoff = 30;

    /** A Clio "updated" event during the delay would otherwise queue a second check. */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $invoiceId,
    ) {}

    public static function dispatchDelayed(Invoice $invoice): void
    {
        self::dispatch((string) $invoice->id)->delay(now()->addSeconds(self::DELAY_SECONDS));
    }

    public function uniqueId(): string
    {
        return $this->invoiceId;
    }

    public function handle(
        InvoiceLiveStatusResolver $resolver,
        PaymentLinkService $paymentLinks,
        LawcusMatterActivityService $lawcusMatterActivity,
    ): void {
        $invoice = Invoice::query()->find($this->invoiceId);
        $session = $invoice
            ? PaymentSession::query()->where('invoice_id', $invoice->id)->latest('created_at')->first()
            : null;

        // Already sent (e.g. staff used Resend), paid, or the link was disabled meanwhile.
        if (! $session || $session->payment_link_sent_at !== null
            || $session->status === 'COMPLETED' || $session->link_status !== 'active') {
            return;
        }

        $status = $resolver->resolve($invoice);

        if ($status === 'paid' || $status === 'gone') {
            if ($status === 'paid') {
                $session->forceFill(['link_status' => 'paid'])->save();
            }

            AuditLogger::log('PAYMENT_LINK_SKIPPED', 'payment_session', $session->id, [
                'invoice_id'    => $invoice->id,
                'pms_client_id' => $invoice->pms_client_id,
                'reason'        => $status === 'paid' ? 'nothing_due' : 'gone',
            ]);

            return;
        }

        if ($status === 'unknown') {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff);

                return;
            }

            // Never email a link we couldn't confirm is still owed; staff can still Resend.
            AuditLogger::log('PAYMENT_LINK_HELD', 'payment_session', $session->id, [
                'invoice_id'    => $invoice->id,
                'pms_client_id' => $invoice->pms_client_id,
                'reason'        => 'trust_balance_unconfirmed',
            ]);

            return;
        }

        $recipientEmails = (array) ($invoice->recipient_emails ?? []);
        $emailsSent      = $paymentLinks->sendInvoiceLinkOnce($invoice, $session, $recipientEmails);

        if ($emailsSent > 0) {
            AuditLogger::log('PAYMENT_LINK_SENT', 'payment_session', $session->id, [
                'invoice_id'       => $invoice->id,
                'pms_client_id'    => $invoice->pms_client_id,
                'emails_sent'      => $emailsSent,
                'recipient_emails' => $recipientEmails,
            ]);

            if ($invoice->pms_source === 'lawcus') {
                $lawcusMatterActivity->logInvoiceSent($invoice, $recipientEmails);
            }
        }
    }
}
