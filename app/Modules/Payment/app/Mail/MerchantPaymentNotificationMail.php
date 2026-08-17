<?php

namespace Modules\Payment\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;

class MerchantPaymentNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Transaction $transaction,
        public Invoice $invoice,
        public ?Client $client = null,
    ) {}

    public function envelope(): Envelope
    {
        $invoiceRef   = $this->invoice->invoice_number ?? $this->invoice->external_invoice_id;
        $customerName = (string) ($this->invoice->customer['name'] ?? '');
        $subject      = $customerName !== ''
            ? "Payment Received — Invoice #{$invoiceRef} from {$customerName}"
            : "Payment Received — Invoice #{$invoiceRef}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $invoice     = $this->invoice;
        $transaction = $this->transaction;

        $logoUrl = null;
        if ($this->client?->logo_path && Storage::disk('public')->exists($this->client->logo_path)) {
            $logoUrl = rtrim(config('app.url'), '/') . '/storage/' . $this->client->logo_path;
        }

        $currency      = $invoice->currency ?? 'USD';
        $invoiceRef    = $invoice->invoice_number ?? $invoice->external_invoice_id ?? '';
        $merchantName  = $this->client?->client_name ?? '';
        $customerName  = (string) ($invoice->customer['name'] ?? '');
        $customerEmail = (string) ($invoice->customer['email'] ?? $invoice->recipient_emails[0] ?? '');
        $pmsSource     = $this->pmsLabel((string) $invoice->pms_source);

        $invoiceAmount   = number_format($invoice->amount_cents / 100, 2);
        $feeCents        = (int) $transaction->fee_cents;
        $feeAmount       = $feeCents > 0 ? number_format($feeCents / 100, 2) : null;
        $totalAmount     = number_format($transaction->amount_cents / 100, 2);
        $paymentMethod   = $this->gatewayLabel((string) $transaction->gateway);
        $authorizationId = (string) ($transaction->gateway_txn_id ?? '');
        $paidDate        = $transaction->created_at
            ? $transaction->created_at->format('m/d/Y g:i A') . ' UTC'
            : now()->format('m/d/Y g:i A') . ' UTC';

        // Clio's API can only push a bill to "awaiting_approval" - fully recording/
        // approving the payment still requires a human in Clio's own UI, so point
        // the firm straight at the bill.
        $clioBillUrl = strtolower((string) $invoice->pms_source) === 'clio' && $invoice->external_invoice_id
            ? 'https://app.clio.com/nc/#/bills/' . $invoice->external_invoice_id
            : null;

        return new Content(
            view: 'payment::emails.payment-merchant-notification',
            with: compact(
                'logoUrl', 'merchantName', 'customerName', 'customerEmail',
                'invoiceRef', 'currency', 'pmsSource',
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

    private function pmsLabel(string $pmsSource): string
    {
        return match (strtolower($pmsSource)) {
            'quickbooks' => 'QuickBooks',
            'clio'       => 'Clio',
            'zoho'       => 'Zoho Books',
            'lawcus'     => 'Lawcus',
            'wave'       => 'Wave',
            'custom'     => 'Custom',
            default      => ucfirst($pmsSource),
        };
    }
}
