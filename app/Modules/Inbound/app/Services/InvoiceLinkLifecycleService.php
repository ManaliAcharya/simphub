<?php

namespace Modules\Inbound\Services;

use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Models\Client;
use Modules\Payment\Services\PaymentLinkService;

/**
 * Shared invoice-update lifecycle handling for non-QuickBooks PMS integrations
 * (Clio, Zoho, Wave). QuickBooks keeps its own dedicated mechanism
 * (ProcessQBInvoiceLinkJob) — this service mirrors the same two behaviors
 * (resend on update, disable on delete/void) for the others.
 */
class InvoiceLinkLifecycleService
{
    public function __construct(
        private readonly PaymentLinkService $paymentLinks,
    ) {}

    /**
     * Re-send the payment link when an already-processed invoice is updated
     * in the PMS, regardless of whether the amount actually changed.
     */
    public function resendOnUpdate(Invoice $invoice, PaymentSession $session, array $emails, string $pmsClientId): void
    {
        if ($session->payment_link_sent_at === null) {
            return; // first send handles this — nothing to resend yet
        }

        $client = Client::query()->where('pms_client_id', $pmsClientId)->first();

        if (! ($client?->auto_resend_on_change)) {
            return;
        }

        $emailsSent = $this->paymentLinks->resendPaymentLink($invoice, $session, $emails);

        if ($emailsSent > 0) {
            AuditLogger::log('payment_link.resent_on_invoice_update', 'payment_session', $session->id, [
                'invoice_id'    => $invoice->id,
                'pms_source'    => $invoice->pms_source,
                'pms_client_id' => $pmsClientId,
                'emails_sent'   => $emailsSent,
            ]);
        }
    }

    /**
     * Disable the active payment link when the PMS reports the invoice was
     * deleted or voided. Does not re-fetch or re-ingest the invoice.
     */
    public function disableOnRemoval(string $pmsSource, string $externalInvoiceId, string $pmsClientId, string $status): void
    {
        $invoice = Invoice::query()
            ->where('pms_source', $pmsSource)
            ->where('pms_client_id', $pmsClientId)
            ->where('external_invoice_id', $externalInvoiceId)
            ->first();

        if (! $invoice) {
            return;
        }

        $session = PaymentSession::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('link_status', ['active'])
            ->latest('created_at')
            ->first();

        if (! $session) {
            return;
        }

        $session->forceFill(['link_status' => $status])->save();

        AuditLogger::log("payment_link.{$status}", 'payment_session', $session->id, [
            'invoice_id'          => $invoice->id,
            'pms_source'          => $pmsSource,
            'pms_client_id'       => $pmsClientId,
            'external_invoice_id' => $externalInvoiceId,
        ]);
    }
}
