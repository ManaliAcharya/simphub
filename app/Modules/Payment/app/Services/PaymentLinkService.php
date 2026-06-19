<?php

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Models\Client;
use Modules\Payment\Mail\PaymentLinkAdminMail;
use Modules\Payment\Mail\PaymentLinkMail;

class PaymentLinkService
{
    public function urlForSession(PaymentSession $session): string
    {
        return $this->baseUrl().route('payment.page.show', ['session' => $session->hosted_url_token], false);
    }

    public function sendInvoiceLinkOnce(Invoice $invoice, PaymentSession $session, array $emails): int
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

        // Claim the session — prevents duplicate sends on webhook retries
        $claimed = DB::table('payment_sessions')
            ->where('id', $session->id)
            ->whereNull('payment_link_sent_at')
            ->update([
                'payment_link_sent_at'       => now(),
                'payment_link_last_sent_to'  => json_encode($allRecipients, JSON_THROW_ON_ERROR),
                'updated_at'                 => now(),
            ]);

        if ($claimed !== 1) {
            return 0;
        }

        // Do NOT reset payment_link_sent_at on delivery failure. Resetting it lets a PMS
        // webhook retry bypass the once-only guard and send a duplicate email. The claim
        // stays set; an admin can null-out payment_link_sent_at to trigger a resend.
        $paymentUrl = $this->urlForSession($session);
        $sent       = 0;

        foreach ($toCustomer as $email) {
            Mail::to($email)->send(new PaymentLinkMail($invoice, $session, $paymentUrl));
            $sent++;
        }

        if ($toAdmin !== []) {
            Mail::to($toAdmin[0])->send(new PaymentLinkAdminMail($invoice, $session, $paymentUrl));
            $sent++;
        }

        return $sent;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.payment.host_url', config('app.url')), '/');
    }
}
