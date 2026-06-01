<?php

namespace Modules\Payment\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Models\Client;

class PaymentLinkMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public PaymentSession $session,
        public string $paymentUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invoice payment link',
        );
    }

    public function content(): Content
    {
        $logoUrl = null;
        if ($this->invoice->pms_client_id) {
            $logoPath = Client::query()
                ->where('pms_client_id', $this->invoice->pms_client_id)
                ->value('logo_path');
            if ($logoPath) {
                $logoUrl = Storage::disk('public')->url($logoPath);
            }
        }

        return new Content(
            view: 'payment::emails.payment-link',
            with: ['logoUrl' => $logoUrl],
        );
    }
}
