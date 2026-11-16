<?php

namespace Modules\Payment\Services;

use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Models\Client;
use Modules\Payment\Jobs\SendInvoiceReminder;

/**
 * Pause / Resume on the invoice list, for every PMS. Pausing stops an invoice's reminders —
 * e.g. the client says a check is in the mail. Resuming sends a reminder right away and
 * restarts the reminder schedule from that moment, so the next one is the first step of
 * the schedule counted from the resume.
 */
class InvoiceReminderPause
{
    /** Whether the invoice list offers Pause / Resume for this invoice's payment session. */
    public static function available(?Client $client, Invoice $invoice, ?PaymentSession $session): bool
    {
        return (bool) $client?->reminders_enabled
            && $session !== null
            && $session->first_email_sent_at !== null
            && $session->link_status === 'active'
            && $session->status !== 'COMPLETED'
            && strtoupper((string) $invoice->status) !== 'PAID';
    }

    public function pause(PaymentSession $session): void
    {
        if ($session->reminders_paused_at !== null) {
            return;
        }

        $session->forceFill([
            'reminders_paused_at' => now(),
            'next_reminder_at'    => null,
        ])->save();

        AuditLogger::log('payment_reminders_paused', 'payment_session', $session->id, [
            'invoice_id' => $session->invoice_id,
        ]);
    }

    /**
     * The schedule restarts here before the immediate reminder is sent, so a failed send
     * still leaves the next scheduled reminder in place.
     */
    public function resume(PaymentSession $session, Client $client): void
    {
        if ($session->reminders_paused_at === null) {
            return;
        }

        $schedule = $client->reminderScheduleDays();

        $session->forceFill([
            'reminders_paused_at'       => null,
            'reminder_cycle_started_at' => now(),
            'reminder_cycle_offset'     => (int) $session->reminders_sent_count,
            'next_reminder_at'          => now()->addDays((int) $schedule[0]),
        ])->save();

        AuditLogger::log('payment_reminders_resumed', 'payment_session', $session->id, [
            'invoice_id'       => $session->invoice_id,
            'next_reminder_at' => $session->next_reminder_at?->toDateTimeString(),
        ]);

        SendInvoiceReminder::dispatch($session->id, true);
    }

    /**
     * The updated invoice was just emailed (the firm edited and re-saved it): a pause no
     * longer holds — e.g. a late fee added after the client said the check is in the mail —
     * and the reminder schedule starts over from that email, like a resume without the
     * extra immediate reminder.
     */
    public function restartAfterResend(PaymentSession $session): void
    {
        $session = $session->fresh() ?? $session;
        $client  = Client::query()->where('pms_client_id', $session->invoice?->pms_client_id)->first();
        $paused  = $session->reminders_paused_at !== null;

        $session->forceFill([
            'reminders_paused_at'       => null,
            'reminder_cycle_started_at' => now(),
            'reminder_cycle_offset'     => (int) $session->reminders_sent_count,
            'next_reminder_at'          => $client?->reminders_enabled
                ? now()->addDays((int) $client->reminderScheduleDays()[0])
                : null,
        ])->save();

        AuditLogger::log('payment_reminders_restarted_on_resend', 'payment_session', $session->id, [
            'invoice_id'       => $session->invoice_id,
            'was_paused'       => $paused,
            'next_reminder_at' => $session->next_reminder_at?->toDateTimeString(),
        ]);
    }
}
