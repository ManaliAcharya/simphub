<?php

namespace Modules\Payment\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
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
        public ?string $pdfContent = null,
        public bool $isResend = false,
    ) {}

    public function envelope(): Envelope
    {
        $invoiceRef = $this->invoice->invoice_number ?? $this->invoice->external_invoice_id;
        $subject    = ($this->isResend ? 'Updated: ' : '') . "Payment link for Invoice #{$invoiceRef}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $client = $this->invoice->pms_client_id
            ? Client::query()->where('pms_client_id', $this->invoice->pms_client_id)->first()
            : null;

        $logoUrl      = null;
        $merchantName = $client?->client_name;

        if ($client?->logo_path && Storage::disk('public')->exists($client->logo_path)) {
            $logoUrl = rtrim(config('app.url'), '/') . '/storage/' . $client->logo_path;
        }

        return new Content(
            view: 'payment::emails.payment-link',
            with: compact('logoUrl', 'merchantName'),
        );
    }

    public function attachments(): array
    {
        if ($this->pdfContent === null || $this->pdfContent === '') {
            return [];
        }

        $invoiceRef = $this->invoice->invoice_number ?? $this->invoice->external_invoice_id;

        return [
            Attachment::fromData(fn () => $this->pdfContent, "Invoice-{$invoiceRef}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
