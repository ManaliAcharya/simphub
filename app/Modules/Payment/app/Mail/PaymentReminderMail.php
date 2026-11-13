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
use Modules\Inbound\Services\LawcusTrustBalance;
use Modules\Payment\Services\PaymentEmailTemplate;

class PaymentReminderMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public PaymentSession $session,
        public string $paymentUrl,
        public string $subjectTemplate,
        public ?string $pdfContent = null,
        public ?EmailConfiguration $emailConfig = null,
        public ?string $fromName = null,
    ) {}

    public function envelope(): Envelope
    {
        $invoiceRef = $this->invoice->invoice_number ?? $this->invoice->external_invoice_id;

        $subject = $this->replaceVariables($this->subjectTemplate, [
            'invoice_number' => (string) $invoiceRef,
            'amount'         => ($this->invoice->currency ?? 'USD') . ' ' . number_format($this->invoice->amount_cents / 100, 2),
        ]);

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

        return new Content(
            view: 'payment::emails.reminder',
            with: [
                'logoUrl'      => $logoUrl,
                'merchantName' => $merchantName,
                'primaryColor' => $primaryColor,
                'customerName' => $customerName,
                'dueDate'      => $dueDate,
                // A Trust Request's reminder asks for the retainer deposit, not an invoice.
                'isRetainer'   => $this->template()['kind'] === PaymentEmailTemplate::RETAINER,
                'trustBalance' => app(LawcusTrustBalance::class)->formatted($this->invoice),
            ],
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
