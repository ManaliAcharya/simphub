<?php

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\PaymentSession;

/**
 * Closing and reopening a payment link, with the reason it was closed.
 *
 * Only a link closed because the PMS recorded a payment (e.g. a Payment entered in
 * QuickBooks) is ever reopened, and only when nothing was paid through SimpHub: a
 * SimpHub payment is written back to the PMS afterwards, and until that lands the PMS
 * still shows the full balance — reopening on it would let the customer pay twice.
 */
class PaymentLinkClosure
{
    public const PAID_VIA_SIMPHUB = 'paid_via_simphub';
    public const PAID_IN_PMS      = 'paid_in_pms';
    public const VOIDED_IN_PMS    = 'voided_in_pms';
    public const REMOVED_IN_PMS   = 'removed_in_pms';

    /** link_status each reason closes the link with. */
    private const LINK_STATUS = [
        self::PAID_VIA_SIMPHUB => 'paid',
        self::PAID_IN_PMS      => 'paid',
        self::VOIDED_IN_PMS    => 'voided',
        self::REMOVED_IN_PMS   => 'disabled',
    ];

    /** Short wording for the invoice list and the merchant-facing messages. */
    public const LABELS = [
        self::PAID_VIA_SIMPHUB => 'Paid through SimpHub',
        self::PAID_IN_PMS      => 'Marked paid in the PMS',
        self::VOIDED_IN_PMS    => 'Voided in the PMS',
        self::REMOVED_IN_PMS   => 'Removed in the PMS',
    ];

    /**
     * Columns that close a link — for callers that update in bulk. Any scheduled reminder
     * is cleared with it, so a link reopened later never fires a stale one.
     */
    public static function attributes(string $reason): array
    {
        return [
            'link_status'        => self::LINK_STATUS[$reason],
            'link_closed_reason' => $reason,
            'link_closed_at'     => now(),
            'next_reminder_at'   => null,
        ];
    }

    public static function close(PaymentSession $session, string $reason): void
    {
        $session->forceFill(self::attributes($reason))->save();
    }

    /** The paid reason for a session: SimpHub if its own checkout completed, else the PMS. */
    public static function paidReason(PaymentSession $session): string
    {
        return $session->status === 'COMPLETED' ? self::PAID_VIA_SIMPHUB : self::PAID_IN_PMS;
    }

    public static function paidThroughSimphub(PaymentSession $session): bool
    {
        return $session->status === 'COMPLETED'
            || $session->link_closed_reason === self::PAID_VIA_SIMPHUB
            || DB::table('transactions')
                ->where('invoice_id', $session->invoice_id)
                ->where('status', '!=', 'FAILED')
                ->exists();
    }

    /** Whether a balance back in the PMS may reopen this link. */
    public static function reopenable(PaymentSession $session): bool
    {
        return $session->link_status === 'paid'
            && $session->link_closed_reason === self::PAID_IN_PMS
            && ! self::paidThroughSimphub($session);
    }

    /**
     * Reopen the link. Sends nothing and schedules no reminder: the customer hears about it
     * only when someone sends the link again (Resend restarts the reminders then).
     */
    public static function reopen(PaymentSession $session, array $audit = []): void
    {
        $previousReason = $session->link_closed_reason;

        $session->forceFill([
            'link_status'        => 'active',
            'link_closed_reason' => null,
            'link_closed_at'     => null,
            'next_reminder_at'   => null,
        ])->save();

        AuditLogger::log('payment_link.reopened', 'payment_session', $session->id, [
            'invoice_id'      => $session->invoice_id,
            'previous_reason' => $previousReason,
        ] + $audit);
    }
}
