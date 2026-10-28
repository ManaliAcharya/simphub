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

/**
 * Tells the firm an ACH payment came back returned, so the payment SimpHub already
 * recorded in its PMS (e.g. Lawcus) must be reversed there — on the trust ledger when it
 * was paid into the IOLTA account.
 */
class AchReturnedNotificationMail extends Mailable
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
        $invoiceRef = $this->invoice->invoice_number ?? $this->invoice->external_invoice_id;

        return new Envelope(subject: "Action needed: ACH payment returned for Invoice #{$invoiceRef}");
    }

    public function content(): Content
    {
        $invoice     = $this->invoice;
        $transaction = $this->transaction;

        $logoUrl = null;
        if ($this->client?->logo_path && Storage::disk('public')->exists($this->client->logo_path)) {
            $logoUrl = rtrim(config('app.url'), '/') . '/storage/' . $this->client->logo_path;
        }

        $isLawcus      = strtolower((string) $invoice->pms_source) === 'lawcus';
        $isTrust       = strtoupper((string) $transaction->fund_type) === 'TRUST';
        $currency      = $invoice->currency ?? 'USD';
        $invoiceRef    = $invoice->invoice_number ?? $invoice->external_invoice_id ?? '';
        $merchantName  = $this->client?->client_name ?? '';
        $customerName  = (string) ($invoice->customer['name'] ?? '');
        $pmsSource     = $isLawcus ? 'Lawcus' : ucfirst((string) $invoice->pms_source);
        $documentLabel = $isLawcus && $isTrust ? 'Trust request' : 'Invoice';
        $accountLabel  = $isTrust ? 'IOLTA (trust) account' : 'Operating account';
        $totalAmount   = number_format($transaction->amount_cents / 100, 2);
        $returnCode    = (string) ($transaction->return_code ?? '');
        $returnReason  = (string) ($transaction->return_reason ?? '');
        $authorizationId = (string) ($transaction->gateway_txn_id ?? '');
        $lawcusMatterUrl = MerchantPaymentNotificationMail::lawcusMatterUrl($invoice);
        $paidDate      = $transaction->created_at?->format('m/d/Y') ?? '';
        $returnedDate  = ($transaction->returned_at ?? now())->format('m/d/Y');

        return new Content(
            view: 'payment::emails.ach-returned-notification',
            with: compact(
                'logoUrl', 'merchantName', 'customerName', 'invoiceRef', 'currency', 'pmsSource',
                'documentLabel', 'accountLabel', 'isTrust', 'totalAmount', 'returnCode', 'returnReason',
                'authorizationId', 'lawcusMatterUrl', 'paidDate', 'returnedDate',
            ),
        );
    }
}
