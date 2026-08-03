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

class PaymentReminderAdminMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public PaymentSession $session,
        public string $paymentUrl,
        public string $subjectTemplate,
    ) {}

    public function envelope(): Envelope
    {
        $invoiceRef = $this->invoice->invoice_number ?? $this->invoice->external_invoice_id;

        $subject = str_replace(
            ['{invoice_number}', '{amount}'],
            [(string) $invoiceRef, ($this->invoice->currency ?? 'USD') . ' ' . number_format($this->invoice->amount_cents / 100, 2)],
            $this->subjectTemplate,
        );

        return new Envelope(subject: "[Reminder] {$subject}");
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

        $customerName  = $this->invoice->customer['name'] ?? null;
        $customerEmail = $this->invoice->customer['email']
            ?? ($this->invoice->recipient_emails[0] ?? null);

        $invoiceRef = $this->invoice->invoice_number ?? $this->invoice->external_invoice_id;
        $amount     = number_format($this->invoice->amount_cents / 100, 2);
        $currency   = $this->invoice->currency ?? 'USD';

        return new Content(
            view: 'payment::emails.reminder-admin',
            with: compact('logoUrl', 'merchantName', 'customerName', 'customerEmail', 'invoiceRef', 'amount', 'currency'),
        );
    }
}
