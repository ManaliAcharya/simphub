<?php

namespace Modules\Payment\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\EmailConfiguration;
use Modules\Payment\Services\PaymentEmailTemplate;

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
        public ?EmailConfiguration $emailConfig = null,
        public ?string $fromName = null,
    ) {}

    public function envelope(): Envelope
    {
        $invoiceRef = $this->invoice->invoice_number ?? $this->invoice->external_invoice_id;

        $emailTemplate = $this->template();
        $template      = $this->isResend ? $emailTemplate['subject_updated'] : $emailTemplate['subject'];

        if ($template) {
            $subject = $this->replaceVariables((string) $template, [
                'invoice_number' => (string) $invoiceRef,
                'amount'         => ($this->invoice->currency ?? 'USD') . ' ' . number_format($this->invoice->amount_cents / 100, 2),
            ]);
        } else {
            $subject = ($this->isResend ? 'Updated: ' : '') . "Payment link for Invoice #{$invoiceRef}";
        }

        $replyTo = [];
        if ($this->emailConfig?->reply_to_email) {
            $replyTo = [new Address($this->emailConfig->reply_to_email, $this->emailConfig->reply_to_name ?? '')];
        }

        $from = $this->fromName
            ? new Address((string) config('mail.from.address'), $this->fromName)
            : null;

        return new Envelope(from: $from, subject: $subject, replyTo: $replyTo);
    }

    public function content(): Content
    {
        $client = $this->invoice->pms_client_id
            ? Client::query()->where('pms_client_id', $this->invoice->pms_client_id)->first()
            : null;

        $merchantName = $client?->client_name;

        if ($client?->logo_path && Storage::disk('public')->exists($client->logo_path)) {
            $logoUrl = rtrim(config('app.url'), '/') . '/storage/' . $client->logo_path;
        } else {
            $logoUrl = null;
        }

        $primaryColor = $this->emailConfig?->primary_color ?? '#2196F3';
        $customerName = $this->extractCustomerName();
        $dueDate      = $this->extractDueDate();

        $templateData = [
            'merchant_name'  => $merchantName ?? '',
            'customer_name'  => $customerName,
            'invoice_number' => (string) ($this->invoice->invoice_number ?? $this->invoice->external_invoice_id ?? ''),
            'amount'         => ($this->invoice->currency ?? 'USD') . ' ' . number_format($this->invoice->amount_cents / 100, 2),
            'due_date'       => $dueDate,
            'payment_link'   => $this->paymentUrl,
            'merchant_phone' => '',
            'merchant_email' => $this->emailConfig?->reply_to_email ?? '',
        ];

        $emailTemplate = $this->template();

        $bodyHeader = $emailTemplate['body_header']
            ? $this->replaceVariables($emailTemplate['body_header'], $templateData)
            : null;

        $bodyFooter = $emailTemplate['body_footer']
            ? $this->replaceVariables($emailTemplate['body_footer'], $templateData)
            : null;

        // A Trust Request reads as a retainer deposit request, never as an invoice.
        $isRetainer = $emailTemplate['kind'] === PaymentEmailTemplate::RETAINER;

        return new Content(
            view: 'payment::emails.payment-link',
            with: compact('logoUrl', 'merchantName', 'primaryColor', 'bodyHeader', 'bodyFooter', 'customerName', 'dueDate', 'isRetainer'),
        );
    }

    public function attachments(): array
    {
        if (! $this->template()['attach_pdf']) {
            return [];
        }

        if ($this->pdfContent === null || $this->pdfContent === '') {
            return [];
        }

        $invoiceRef = $this->invoice->invoice_number ?? $this->invoice->external_invoice_id;

        $fileLabel  = $this->template()['kind'] === PaymentEmailTemplate::RETAINER ? 'Retainer-Request' : 'Invoice';

        return [
            Attachment::fromData(fn () => $this->pdfContent, "{$fileLabel}-{$invoiceRef}.pdf")
                ->withMime('application/pdf'),
        ];
    }

    /** The template for this invoice's trigger (billable, flat fee or retainer request). */
    private function template(): array
    {
        return PaymentEmailTemplate::resolve($this->invoice, $this->emailConfig);
    }

    private function replaceVariables(string $template, array $data): string
    {
        $search  = array_map(fn ($k) => "{{$k}}", array_keys($data));
        $replace = array_values($data);

        return str_replace($search, $replace, $template);
    }

    private function extractCustomerName(): string
    {
        $raw = $this->invoice->raw_payload ?? [];

        return (string) (
            Arr::get($raw, 'customer.Customer.DisplayName')
            ?? Arr::get($raw, 'customer.data.display_number')
            ?? Arr::get($this->invoice->customer ?? [], 'name')
            ?? ''
        );
    }

    private function extractDueDate(): string
    {
        $raw = $this->invoice->raw_payload ?? [];

        return (string) (
            Arr::get($raw, 'invoice.Invoice.DueDate')
            ?? Arr::get($raw, 'invoice.data.due_at')
            ?? ''
        );
    }
}
