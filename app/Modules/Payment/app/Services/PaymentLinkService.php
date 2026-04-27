<?php

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Payment\Mail\PaymentLinkMail;

class PaymentLinkService
{
    public function urlForSession(PaymentSession $session): string
    {
        return $this->baseUrl().route('payment.page.show', ['session' => $session->hosted_url_token], false);
    }

    public function sendInvoiceLinkOnce(Invoice $invoice, PaymentSession $session, array $emails): int
    {
        $emails = array_values(array_unique(array_filter(array_map(
            static fn ($email) => is_string($email) ? trim(strtolower($email)) : null,
            $emails
        ))));

        if ($emails === []) {
            return 0;
        }

        $claimed = DB::table('payment_sessions')
            ->where('id', $session->id)
            ->whereNull('payment_link_sent_at')
            ->update([
                'payment_link_sent_at' => now(),
                'payment_link_last_sent_to' => json_encode($emails, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return 0;
        }

        $paymentUrl = $this->urlForSession($session);

        try {
            foreach ($emails as $email) {
                Mail::to($email)->send(new PaymentLinkMail($invoice, $session, $paymentUrl));
            }
        } catch (\Throwable $exception) {
            DB::table('payment_sessions')
                ->where('id', $session->id)
                ->update([
                    'payment_link_sent_at' => null,
                    'payment_link_last_sent_to' => null,
                    'updated_at' => now(),
                ]);

            throw $exception;
        }

        return count($emails);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.payment.host_url', config('app.url')), '/');
    }
}
