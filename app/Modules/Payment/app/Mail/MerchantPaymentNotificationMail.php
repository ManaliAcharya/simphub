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
use Modules\Inbound\Services\LawcusLinks;

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
        $invoiceRef = $this->invoice->invoice_number ?? $this->invoice->external_invoice_id;

        return new Envelope(subject: "You Got Paid! 💰 Invoice #{$invoiceRef} is Confirmed");
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
        $coveredInvoices = PaymentConfirmationMail::coveredInvoiceRows($transaction);
        $feeCents        = (int) $transaction->fee_cents;
        $feeAmount       = $feeCents > 0 ? number_format($feeCents / 100, 2) : null;
        $totalAmount     = number_format($transaction->amount_cents / 100, 2);
        $paymentMethod   = $this->gatewayLabel((string) $transaction->gateway);
        $authorizationId = (string) ($transaction->gateway_txn_id ?? '');
        $lawcusMatterUrl = self::lawcusMatterUrl($invoice);
        $paidDate        = $transaction->created_at
            ? $transaction->created_at->format('m/d/Y g:i A') . ' UTC'
            : now()->format('m/d/Y g:i A') . ' UTC';

        $isLawcus       = strtolower((string) $invoice->pms_source) === 'lawcus';
        $depositAccount = $isLawcus ? $this->lawcusDepositAccount($invoice) : null;
        $isTrust        = $isLawcus && $invoice->fund_type === 'TRUST';
        // The PMS sync runs before this email. A Lawcus payment that wasn't confirmed gets
        // its own "Action needed" alert, so this email mustn't say it was marked paid.
        $markedPaid     = ! $isLawcus || $invoice->pms_sync_status === 'SYNCED';

        return new Content(
            view: 'payment::emails.payment-merchant-notification',
            with: compact(
                'logoUrl', 'merchantName', 'customerName', 'customerEmail',
                'invoiceRef', 'currency', 'pmsSource',
                'invoiceAmount', 'feeAmount', 'feeCents', 'totalAmount',
                'paymentMethod', 'authorizationId', 'paidDate', 'lawcusMatterUrl',
                'coveredInvoices', 'depositAccount', 'isTrust', 'markedPaid',
            ),
        );
    }

    /** "Trust (IOLTA)" or "Operating", with the firm's Lawcus account name when one is set. */
    private function lawcusDepositAccount(Invoice $invoice): string
    {
        $isTrust = $invoice->fund_type === 'TRUST';
        $label   = $isTrust ? 'Trust (IOLTA)' : 'Operating';
        $name    = trim((string) ($isTrust
            ? $this->client?->lawcus_trust_bank_account_name
            : $this->client?->lawcus_default_bank_account_name));

        return $name !== '' && strcasecmp($name, $label) !== 0 ? "{$label}: {$name}" : $label;
    }

    /**
     * Lawcus invoices are always created from a matter, so the firm can jump straight
     * to it. Matter pages are addressed by the matter's UUID, and app.lawcus.com opens
     * them for any firm (it redirects to the firm's own subdomain). Null for every
     * other PMS, or when the invoice carries no matter UUID.
     */
    public static function lawcusMatterUrl(Invoice $invoice): ?string
    {
        return LawcusLinks::matterUrl($invoice);
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
