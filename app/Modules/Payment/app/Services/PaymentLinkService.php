<?php

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\Mail;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Payment\Mail\PaymentLinkMail;

class PaymentLinkService
{
    public function urlForSession(PaymentSession $session): string
    {
        return route('payment.page.show', ['session' => $session->hosted_url_token]);
    }

    public function sendInvoiceLink(Invoice $invoice, PaymentSession $session, array $emails): int
    {
        $emails = array_values(array_unique(array_filter($emails)));

        if ($emails === []) {
            return 0;
        }

        $paymentUrl = $this->urlForSession($session);

        foreach ($emails as $email) {
            Mail::to($email)->send(new PaymentLinkMail($invoice, $session, $paymentUrl));
        }

        return count($emails);
    }
}
