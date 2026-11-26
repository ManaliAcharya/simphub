<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\Log;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Jobs\ProcessQBInvoiceLinkJob;
use Modules\Inbound\Models\QuickBooksConnection;
use Modules\Payment\Services\PaymentLinkClosure;

/**
 * Run before Resend on the invoice list, so a merchant never emails a customer a link
 * that only says "already paid" or "no longer available".
 *
 * QuickBooks invoices are checked against the live QuickBooks balance: a link closed only
 * because a payment was recorded in QuickBooks is reopened when a balance is due again,
 * and nothing is sent when the balance is $0. For every PMS, a link paid through SimpHub
 * or closed for any other reason is not sent.
 */
class ResendLinkCheck
{
    public function __construct(
        private readonly QuickBooksApiClient $qbApi,
    ) {}

    /** Null when the link may be sent; otherwise why not, for the merchant. */
    public function blockReason(Invoice $invoice, PaymentSession $session, mixed $connection): ?string
    {
        // This link's own checkout completed (a returned ACH payment leaves it so too):
        // the page would only show the existing payment, never take a new one.
        if ($session->status === 'COMPLETED' || $session->link_closed_reason === PaymentLinkClosure::PAID_VIA_SIMPHUB) {
            return 'This invoice was already paid through SimpHub, so nothing was sent.';
        }

        if ((string) $invoice->pms_source === 'quickbooks' && $connection instanceof QuickBooksConnection) {
            $blocked = $this->checkQuickBooks($invoice, $session, $connection);

            if ($blocked !== null) {
                return $blocked;
            }
        }

        $session->refresh();

        if (($session->link_status ?? 'active') !== 'active') {
            $label = PaymentLinkClosure::LABELS[$session->link_closed_reason] ?? 'Closed';

            return "This payment link is closed ({$label}), so nothing was sent.";
        }

        return null;
    }

    private function checkQuickBooks(Invoice $invoice, PaymentSession $session, QuickBooksConnection $connection): ?string
    {
        try {
            $balance = ProcessQBInvoiceLinkJob::liveBalance(
                $this->qbApi->fetchInvoice($connection, (string) $invoice->external_invoice_id)
            );
        } catch (\Throwable $e) {
            Log::warning('ResendLinkCheck: could not read the QuickBooks balance', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);

            return 'Could not check the current balance in QuickBooks, so nothing was sent. Check the QuickBooks connection and try again.';
        }

        if ($balance <= 0) {
            if ($session->link_status === 'active') {
                PaymentLinkClosure::close($session, PaymentLinkClosure::PAID_IN_PMS);

                AuditLogger::log('payment_link.marked_paid_via_qb_payment', 'payment_session', $session->id, [
                    'invoice_id'    => $invoice->id,
                    'qb_invoice_id' => $invoice->external_invoice_id,
                    'reason'        => PaymentLinkClosure::PAID_IN_PMS,
                    'source'        => 'resend_check',
                ]);
            }

            if ($invoice->status !== 'PAID') {
                $invoice->forceFill(['status' => 'PAID'])->save();
            }

            return 'This invoice is already paid in QuickBooks, so nothing was sent.';
        }

        $wasClosed = $session->link_status !== 'active';

        if ($wasClosed && ! PaymentLinkClosure::reopenable($session)) {
            return null; // reported as closed by the caller
        }

        // Send — and charge — the balance QuickBooks shows right now.
        ProcessQBInvoiceLinkJob::applyLiveBalance($invoice, $session, $balance);

        if ($wasClosed) {
            PaymentLinkClosure::reopen($session, [
                'qb_invoice_id' => $invoice->external_invoice_id,
                'qbo_balance'   => $balance,
                'source'        => 'resend',
            ]);
        }

        return null;
    }
}
