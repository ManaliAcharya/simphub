<?php

namespace Modules\Inbound\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Services\LawcusLinks;

/**
 * Asks the firm (and SimpHub ops) to record a payment in Lawcus by hand, because
 * SimpHub couldn't record it automatically or couldn't confirm that it landed.
 */
class LawcusRecordPaymentNeededMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Transaction $transaction,
        public Invoice $invoice,
        public ?Client $client,
        public float $amount,
        public bool $isTrust,
        public string $paymentMethod,
        public string $transactionRef,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Action needed: record payment for Invoice #{$this->invoiceRef()}");
    }

    public function content(): Content
    {
        $logoUrl = null;
        if ($this->client?->logo_path && Storage::disk('public')->exists($this->client->logo_path)) {
            $logoUrl = rtrim(config('app.url'), '/').'/storage/'.$this->client->logo_path;
        }

        return new Content(
            view: 'inbound::emails.lawcus-record-payment-needed',
            with: [
                'logoUrl'        => $logoUrl,
                'merchantName'   => $this->client?->client_name ?? '',
                'invoiceRef'     => $this->invoiceRef(),
                'customerName'   => trim((string) ($this->invoice->customer['name'] ?? '')),
                'matterName'     => LawcusLinks::matterName($this->invoice),
                'actionLink'     => LawcusLinks::actionLink($this->invoice),
                'destination'    => $this->isTrust ? 'Trust (IOLTA)' : 'Operating',
                'isTrust'        => $this->isTrust,
                // Not "amount": public properties reach the view too and would override it.
                'amountDisplay'  => number_format($this->amount, 2),
                'paymentDate'    => ($this->transaction->created_at ?? now())->format('m/d/Y'),
                'paymentMethod'  => $this->paymentMethod,
                'transactionRef' => $this->transactionRef,
            ],
        );
    }

    private function invoiceRef(): string
    {
        return (string) ($this->invoice->invoice_number ?? $this->invoice->external_invoice_id ?? '');
    }
}
