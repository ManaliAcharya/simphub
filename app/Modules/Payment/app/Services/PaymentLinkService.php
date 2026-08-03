<?php

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\EmailConfiguration;
use Modules\Inbound\Models\QuickBooksConnection;
use Modules\Payment\Mail\PaymentLinkAdminMail;
use Modules\Payment\Mail\PaymentLinkMail;
use Modules\Payment\Mail\PaymentReminderAdminMail;
use Modules\Payment\Mail\PaymentReminderMail;

class PaymentLinkService
{
    public function urlForSession(PaymentSession $session): string
    {
        return $this->baseUrl().route('payment.page.show', ['session' => $session->hosted_url_token], false);
    }

    public function sendInvoiceLinkOnce(Invoice $invoice, PaymentSession $session, array $emails, ?string $pdfContent = null): int
    {
        // Normalize incoming customer email list
        $customerEmails = array_values(array_unique(array_filter(array_map(
            static fn ($email) => is_string($email) ? trim(strtolower($email)) : null,
            $emails
        ))));

        // Resolve recipient routing from client config — only when override is enabled
        $client = $invoice->pms_client_id
            ? Client::query()->where('pms_client_id', $invoice->pms_client_id)->first()
            : null;

        $overrideEnabled = (bool) ($client?->payment_link_override_enabled ?? false);
        $recipient       = $overrideEnabled ? ($client?->payment_link_recipient ?? 'customer') : 'customer';
        $adminEmail      = $overrideEnabled && $client?->payment_link_admin_email
            ? trim(strtolower((string) $client->payment_link_admin_email))
            : null;

        // Build per-recipient lists
        $toCustomer = in_array($recipient, ['customer', 'both'], true) ? $customerEmails : [];
        $toAdmin    = in_array($recipient, ['admin', 'both'], true) && $adminEmail ? [$adminEmail] : [];

        // Log a warning when customer emails are missing but expected
        if ($recipient === 'customer' && $toCustomer === []) {
            Log::warning('No customer email on invoice. Payment link not sent.', ['invoice_id' => $invoice->id]);
            return 0;
        }

        if ($recipient === 'both' && $toCustomer === []) {
            Log::warning('No customer email on invoice. Sending payment link to admin only.', ['invoice_id' => $invoice->id]);
        }

        $allRecipients = array_values(array_unique(array_merge($toCustomer, $toAdmin)));

        if ($allRecipients === []) {
            return 0;
        }

        // Anchor for the reminder cadence — set once, alongside the once-only send claim below.
        $firstEmailSentAt = now();
        $nextReminderAt   = null;

        if ($client?->reminders_enabled) {
            $schedule       = $client->reminderScheduleDays();
            $nextReminderAt = $firstEmailSentAt->copy()->addDays((int) $schedule[0]);
        }

        // Claim the session — prevents duplicate sends on webhook retries
        $claimed = DB::table('payment_sessions')
            ->where('id', $session->id)
            ->whereNull('payment_link_sent_at')
            ->update([
                'payment_link_sent_at'       => now(),
                'payment_link_last_sent_to'  => json_encode($allRecipients, JSON_THROW_ON_ERROR),
                'first_email_sent_at'        => $firstEmailSentAt,
                'next_reminder_at'           => $nextReminderAt,
                'updated_at'                 => now(),
            ]);

        if ($claimed !== 1) {
            return 0;
        }

        // Do NOT reset payment_link_sent_at on delivery failure. Resetting it lets a PMS
        // webhook retry bypass the once-only guard and send a duplicate email. The claim
        // stays set; an admin can null-out payment_link_sent_at to trigger a resend.
        $paymentUrl  = $this->urlForSession($session);
        $emailConfig = $client ? EmailConfiguration::where('client_id', $client->id)->first() : null;
        $fromName    = $this->resolveFromName($invoice, $client);
        $sent        = 0;

        foreach ($toCustomer as $email) {
            Mail::to($email)->send(new PaymentLinkMail($invoice, $session, $paymentUrl, $pdfContent, false, $emailConfig, $fromName));
            $sent++;
        }

        if ($toAdmin !== []) {
            Mail::to($toAdmin[0])->send(new PaymentLinkAdminMail($invoice, $session, $paymentUrl));
            $sent++;
        }

        return $sent;
    }

    public function resendPaymentLink(Invoice $invoice, PaymentSession $session, array $emails, ?string $pdfContent = null): int
    {
        $client = $invoice->pms_client_id
            ? Client::query()->where('pms_client_id', $invoice->pms_client_id)->first()
            : null;

        $overrideEnabled = (bool) ($client?->payment_link_override_enabled ?? false);
        $recipient       = $overrideEnabled ? ($client?->payment_link_recipient ?? 'customer') : 'customer';
        $adminEmail      = $overrideEnabled && $client?->payment_link_admin_email
            ? trim(strtolower((string) $client->payment_link_admin_email))
            : null;

        // Emails are passed in fresh from the caller (live QuickBooks data) rather than
        // read from invoice.recipient_emails, which can lag behind the current update.
        $customerEmails = array_values(array_unique(array_filter(array_map(
            static fn ($e) => is_string($e) ? trim(strtolower($e)) : null,
            $emails
        ))));

        $toCustomer = in_array($recipient, ['customer', 'both'], true) ? $customerEmails : [];
        $toAdmin    = in_array($recipient, ['admin', 'both'], true) && $adminEmail ? [$adminEmail] : [];
        $allRecipients = array_values(array_unique(array_merge($toCustomer, $toAdmin)));

        if ($allRecipients === []) {
            Log::warning('resendPaymentLink: no recipients resolved, skipping.', ['invoice_id' => $invoice->id]);
            return 0;
        }

        $paymentUrl  = $this->urlForSession($session);
        $emailConfig = $client ? EmailConfiguration::where('client_id', $client->id)->first() : null;
        $fromName    = $this->resolveFromName($invoice, $client);
        $sent        = 0;
        $sentTo      = [];
        $failures    = [];

        foreach ($toCustomer as $email) {
            try {
                Mail::to($email)->send(new PaymentLinkMail($invoice, $session, $paymentUrl, $pdfContent, true, $emailConfig, $fromName));
                $sent++;
                $sentTo[] = $email;
            } catch (\Throwable $e) {
                $failures[$email] = $e->getMessage();
                Log::error('resendPaymentLink: failed to send customer email', [
                    'invoice_id' => $invoice->id,
                    'session_id' => $session->id,
                    'email'      => $email,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        if ($toAdmin !== []) {
            try {
                Mail::to($toAdmin[0])->send(new PaymentLinkAdminMail($invoice, $session, $paymentUrl));
                $sent++;
                $sentTo[] = $toAdmin[0];
            } catch (\Throwable $e) {
                $failures[$toAdmin[0]] = $e->getMessage();
                Log::error('resendPaymentLink: failed to send admin email', [
                    'invoice_id' => $invoice->id,
                    'session_id' => $session->id,
                    'email'      => $toAdmin[0],
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        // Only record the sent timestamp/recipients for the ones that actually went out —
        // a partial or total failure here must not look identical to a real send in the DB.
        if ($sentTo !== []) {
            DB::table('payment_sessions')
                ->where('id', $session->id)
                ->update([
                    'last_email_sent_at'        => now(),
                    'payment_link_last_sent_to' => json_encode($sentTo, JSON_THROW_ON_ERROR),
                    'updated_at'                => now(),
                ]);
        }

        if ($failures !== []) {
            AuditLogger::log(
                'payment_link.resend_failed',
                'payment_session',
                $session->id,
                [
                    'invoice_id'         => $invoice->id,
                    'attempted_emails'   => $allRecipients,
                    'failed_emails'      => array_keys($failures),
                    'failure_reasons'    => $failures,
                ]
            );
        }

        return $sent;
    }

    /**
     * Send a payment reminder for an invoice that's still unpaid after the initial
     * email — reuses the same recipient routing as sendInvoiceLinkOnce/resendPaymentLink.
     */
    public function sendReminder(Invoice $invoice, PaymentSession $session, array $emails, ?string $pdfContent = null): int
    {
        $client = $invoice->pms_client_id
            ? Client::query()->where('pms_client_id', $invoice->pms_client_id)->first()
            : null;

        $overrideEnabled = (bool) ($client?->payment_link_override_enabled ?? false);
        $recipient       = $overrideEnabled ? ($client?->payment_link_recipient ?? 'customer') : 'customer';
        $adminEmail      = $overrideEnabled && $client?->payment_link_admin_email
            ? trim(strtolower((string) $client->payment_link_admin_email))
            : null;

        $customerEmails = array_values(array_unique(array_filter(array_map(
            static fn ($e) => is_string($e) ? trim(strtolower($e)) : null,
            $emails
        ))));

        $toCustomer = in_array($recipient, ['customer', 'both'], true) ? $customerEmails : [];
        $toAdmin    = in_array($recipient, ['admin', 'both'], true) && $adminEmail ? [$adminEmail] : [];

        if ($recipient === 'customer' && $toCustomer === []) {
            Log::warning('sendReminder: no customer email on invoice, reminder not sent.', ['invoice_id' => $invoice->id]);
            return 0;
        }

        if ($recipient === 'both' && $toCustomer === []) {
            Log::warning('sendReminder: no customer email on invoice, sending reminder to admin only.', ['invoice_id' => $invoice->id]);
        }

        $allRecipients = array_values(array_unique(array_merge($toCustomer, $toAdmin)));

        if ($allRecipients === []) {
            return 0;
        }

        $paymentUrl  = $this->urlForSession($session);
        $emailConfig = $client ? EmailConfiguration::where('client_id', $client->id)->first() : null;
        $subject     = (string) ($client?->reminder_subject_template ?? config('reminders.default_subject_template', 'Reminder: Invoice {invoice_number} is awaiting payment'));
        $fromName    = $this->resolveFromName($invoice, $client);
        $sent        = 0;

        foreach ($toCustomer as $email) {
            Mail::to($email)->send(new PaymentReminderMail($invoice, $session, $paymentUrl, $subject, $pdfContent, $emailConfig, $fromName));
            $sent++;
        }

        if ($toAdmin !== []) {
            Mail::to($toAdmin[0])->send(new PaymentReminderAdminMail($invoice, $session, $paymentUrl, $subject));
            $sent++;
        }

        return $sent;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.payment.host_url', config('app.url')), '/');
    }

    private function resolveFromName(Invoice $invoice, ?Client $client): ?string
    {
        if ((string) $invoice->pms_source !== 'quickbooks' || $client === null) {
            return null;
        }

        $connection = QuickBooksConnection::query()
            ->where('provider', 'quickbooks')
            ->where('pms_client_id', $client->pms_client_id)
            ->first();

        $name = $connection?->companyName() ?? '';

        return $name !== '' ? $name : null;
    }
}
