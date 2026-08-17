<?php

namespace Modules\Payment\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\EmailConfiguration;

class PaymentConfirmationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Transaction $transaction,
        public Invoice $invoice,
        public ?Client $client = null,
        public ?EmailConfiguration $emailConfig = null,
        public ?string $fromName = null,
    ) {}

    public function envelope(): Envelope
    {
        $invoiceRef = $this->invoice->invoice_number ?? $this->invoice->external_invoice_id;
        $subject    = "Payment Confirmed — Invoice #{$invoiceRef}";

        $from = $this->fromName
            ? new Address((string) config('mail.from.address'), $this->fromName)
            : null;

        $replyTo = [];
        if ($this->emailConfig?->reply_to_email) {
            $replyTo = [new Address($this->emailConfig->reply_to_email, $this->emailConfig->reply_to_name ?? '')];
        }

        return new Envelope(from: $from, subject: $subject, replyTo: $replyTo);
    }

    public function content(): Content
    {
        $invoice     = $this->invoice;
        $transaction = $this->transaction;

        $logoUrl = null;
        if ($this->client?->logo_path && Storage::disk('public')->exists($this->client->logo_path)) {
            $logoUrl = rtrim(config('app.url'), '/') . '/storage/' . $this->client->logo_path;
        }

        $currency     = $invoice->currency ?? 'USD';
        $invoiceRef   = $invoice->invoice_number ?? $invoice->external_invoice_id ?? '';
        $merchantName = $this->client?->client_name ?? '';
        $merchantEmail = $this->emailConfig?->reply_to_email ?? '';
        $primaryColor = $this->emailConfig?->primary_color ?? '#2196F3';
        $customerName = (string) ($invoice->customer['name'] ?? '');

        $invoiceAmount = number_format($invoice->amount_cents / 100, 2);
        $feeCents      = (int) $transaction->fee_cents;
        $feeAmount     = $feeCents > 0 ? number_format($feeCents / 100, 2) : null;
        $totalAmount   = number_format($transaction->amount_cents / 100, 2);
        $paymentMethod = $this->gatewayLabel((string) $transaction->gateway);
        $authorizationId = (string) ($transaction->gateway_txn_id ?? '');
        $paidDate      = $transaction->created_at
            ? $transaction->created_at->format('m/d/Y')
            : now()->format('m/d/Y');

        // Clio's API can only push a bill to "awaiting_approval" - fully recording/
        // approving the payment still requires a human in Clio's own UI, so point
        // the firm straight at the bill.
        $clioBillUrl = strtolower((string) $invoice->pms_source) === 'clio' && $invoice->external_invoice_id
            ? 'https://app.clio.com/nc/#/bills/' . $invoice->external_invoice_id
            : null;

        return new Content(
            view: 'payment::emails.payment-confirmation',
            with: compact(
                'logoUrl', 'merchantName', 'merchantEmail', 'primaryColor',
                'customerName', 'invoiceRef', 'currency',
                'invoiceAmount', 'feeAmount', 'feeCents', 'totalAmount',
                'paymentMethod', 'authorizationId', 'paidDate', 'clioBillUrl',
            ),
        );
    }

    private function gatewayLabel(string $gateway): string
    {
        return match (strtolower($gateway)) {
            'paya'     => 'ACH / Bank Transfer',
            'fluidpay' => 'Credit / Debit Card',
            'nmi'      => 'Credit / Debit Card',
            default    => ucfirst($gateway),
        };
    }
}
