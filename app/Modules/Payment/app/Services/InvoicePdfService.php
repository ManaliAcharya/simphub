<?php

namespace Modules\Payment\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClioConnection;
use Modules\Inbound\Models\QuickBooksConnection;
use Modules\Inbound\Models\WaveConnection;
use Modules\Inbound\Services\ClioApiClient;
use Modules\Inbound\Services\ClioOAuthService;
use Modules\Inbound\Services\QuickBooksApiClient;
use Modules\Inbound\Services\QuickBooksOAuthService;
use Modules\Inbound\Services\WaveApiClient;
use Modules\Inbound\Services\WaveOAuthService;
use Modules\Inbound\Models\EmailConfiguration;
use Throwable;

/**
 * Generates a PDF for an invoice from our own data, the same way for every
 * PMS (Clio, Zoho, QuickBooks, Wave, Lawcus) - rather than depending on each
 * PMS's own (inconsistent, sometimes unavailable) invoice/bill PDF export.
 * Never throws: a PDF failure must not block the payment-link email itself.
 */
class InvoicePdfService
{
    public function __construct(
        private readonly ClioApiClient $clioApi,
        private readonly ClioOAuthService $clioOAuth,
        private readonly WaveApiClient $waveApi,
        private readonly WaveOAuthService $waveOAuth,
        private readonly QuickBooksApiClient $qbApi,
        private readonly QuickBooksOAuthService $qbOAuth,
    ) {}

    public function generate(Invoice $invoice, string $paymentUrl): ?string
    {
        try {
            $client = $invoice->pms_client_id
                ? Client::query()->where('pms_client_id', $invoice->pms_client_id)->first()
                : null;

            $logoUrl = null;
            if ($client?->logo_path && Storage::disk('public')->exists($client->logo_path)) {
                $logoUrl = $this->dataUri(
                    Storage::disk('public')->get($client->logo_path),
                    Storage::disk('public')->mimeType($client->logo_path) ?: 'image/png',
                );
            }

            $emailConfig = $client ? EmailConfiguration::where('client_id', $client->id)->first() : null;
            $lineItems   = $this->resolveLineItems($invoice);
            $tax         = $this->resolveTax($invoice);
            $totalAmount = $invoice->amount_cents / 100;
            $taxAmount   = $tax['amount'];
            $subtotal    = $taxAmount !== null ? $totalAmount - $taxAmount : $totalAmount;

            $html = view('payment::pdf.invoice', [
                'logoUrl'          => $logoUrl,
                'faviconUri'       => $this->dataUri(file_get_contents(public_path('images/logo/simphub-favicon.jpeg')), 'image/jpeg'),
                'primaryColor'     => $emailConfig?->primary_color,
                'merchantName'     => $client?->client_name,
                'merchantAddress'  => $this->resolveMerchantAddress($invoice, $client),
                'merchantContact'  => $this->resolveMerchantContact($client, $emailConfig),
                'customerName'     => $this->resolveCustomerName($invoice),
                'customerEmail'    => $this->resolveCustomerEmail($invoice),
                'customerAddress'  => $this->resolveBillingAddress($invoice),
                'shippingAddress'  => $this->resolveShippingAddress($invoice),
                'invoiceNumber'    => (string) ($invoice->invoice_number ?? $invoice->external_invoice_id ?? ''),
                'issueDate'        => $invoice->created_at?->format('F j, Y'),
                'dueDate'          => $this->resolveDueDate($invoice),
                'terms'            => $this->resolveTerms($invoice),
                'customerMemo'     => $this->resolveCustomerMemo($invoice),
                'currency'         => $invoice->currency ?? 'USD',
                'lineItems'        => $lineItems,
                'subtotal'         => number_format($subtotal, 2),
                'taxLabel'         => $tax['label'],
                'taxAmount'        => $taxAmount !== null ? number_format($taxAmount, 2) : null,
                'totalAmount'      => number_format($totalAmount, 2),
                'paymentUrl'       => $paymentUrl,
            ])->render();

            return Pdf::loadHTML($html)->output();
        } catch (Throwable $e) {
            Log::warning('Invoice PDF generation failed, sending payment link without an attachment.', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * dompdf needs isRemoteEnabled to fetch images by URL, which is both an
     * extra security surface and a needless network round-trip for assets we
     * already have locally - embed them as data URIs instead.
     */
    private function dataUri(string $contents, string $mimeType): string
    {
        return 'data:'.$mimeType.';base64,'.base64_encode($contents);
    }

    /**
     * $invoice->customer['name'] is only ever populated after payment (from the
     * cardholder's name entered at checkout) - at payment-link time, before any
     * payment exists, the real name lives in raw_payload with a different shape
     * per PMS. Same sources PaymentLinkMail::extractCustomerName() already reads,
     * plus the Clio/Zoho shapes that weren't covered there.
     */
    private function resolveCustomerName(Invoice $invoice): string
    {
        $fromColumn = (string) ($invoice->customer['name'] ?? '');

        if ($fromColumn !== '') {
            return $fromColumn;
        }

        $raw = $invoice->raw_payload ?? [];

        $name = Arr::get($raw, 'invoice.data.client.name')       // Clio
            ?? Arr::get($raw, 'customer.Customer.DisplayName')   // QuickBooks
            ?? Arr::get($raw, 'invoice.Invoice.CustomerRef.name') // QuickBooks (fallback)
            ?? Arr::get($raw, 'invoice.invoice.customer_name')   // Zoho
            ?? Arr::get($raw, 'invoice.customer.name')           // Wave
            ?? Arr::get($raw, 'customer.data.display_number');   // generic fallback

        return is_string($name) ? $name : '';
    }

    /**
     * Real itemized breakdown, preferring whatever's already sitting in
     * raw_payload (no extra API call): QuickBooks' Line[] and Zoho's
     * line_items[] both carry it there already. Clio and Wave don't capture
     * line items during ingestion, so those fall back to one live API call
     * each - isolated in their own try/catch so a failure there degrades to
     * the single invoice-total line rather than losing the PDF entirely.
     * Lawcus has neither a raw_payload shape nor a confirmed API for this yet.
     */
    private function resolveLineItems(Invoice $invoice): array
    {
        $raw = $invoice->raw_payload ?? [];

        $qbLines = Arr::get($raw, 'invoice.Invoice.Line');
        if (is_array($qbLines)) {
            $items = [];
            foreach ($qbLines as $line) {
                if (($line['DetailType'] ?? null) !== 'SalesItemLineDetail') {
                    continue;
                }
                $amount           = (float) ($line['Amount'] ?? 0);
                $qty              = Arr::get($line, 'SalesItemLineDetail.Qty');
                $rate             = Arr::get($line, 'SalesItemLineDetail.UnitPrice');
                $productOrService = (string) (Arr::get($line, 'SalesItemLineDetail.ItemRef.name') ?? '');
                $description      = (string) ($line['Description'] ?? '');

                if ($productOrService === '' && $description === '') {
                    $description = 'Item';
                }

                $items[] = [
                    'productOrService' => $productOrService,
                    'description'      => $description,
                    'subDescription'   => null,
                    'qty'              => $qty !== null ? (float) $qty : 1.0,
                    'rate'             => $rate !== null ? (float) $rate : $amount,
                    'amount'           => $amount,
                ];
            }
            if ($items !== []) {
                return $items;
            }
        }

        $zohoLines = Arr::get($raw, 'invoice.invoice.line_items');
        if (is_array($zohoLines) && $zohoLines !== []) {
            $items = [];
            foreach ($zohoLines as $line) {
                $amount = (float) ($line['item_total'] ?? 0);
                $qty    = $line['quantity'] ?? null;
                $rate   = $line['rate'] ?? null;
                $items[] = [
                    'description'    => (string) ($line['name'] ?? $line['description'] ?? 'Item'),
                    'subDescription' => null,
                    'qty'            => $qty !== null ? (float) $qty : 1.0,
                    'rate'           => $rate !== null ? (float) $rate : $amount,
                    'amount'         => $amount,
                ];
            }
            if ($items !== []) {
                return $items;
            }
        }

        $pmsSource = strtolower((string) $invoice->pms_source);

        if ($pmsSource === 'clio' && $invoice->external_invoice_id) {
            $items = $this->fetchClioLineItems($invoice);
            if ($items !== []) {
                return $items;
            }
        }

        if ($pmsSource === 'wave' && $invoice->external_invoice_id) {
            $items = $this->fetchWaveLineItems($invoice);
            if ($items !== []) {
                return $items;
            }
        }

        $fallbackAmount = $invoice->amount_cents / 100;

        return [[
            'description'    => 'Invoice #'.((string) ($invoice->invoice_number ?? $invoice->external_invoice_id ?? '')),
            'subDescription' => null,
            'qty'            => 1.0,
            'rate'           => $fallbackAmount,
            'amount'         => $fallbackAmount,
        ]];
    }

    private function fetchClioLineItems(Invoice $invoice): array
    {
        try {
            $connection = ClioConnection::query()
                ->where('provider', 'clio')
                ->where('pms_client_id', $invoice->pms_client_id)
                ->first();

            if (! $connection) {
                return [];
            }

            $connection = $this->clioOAuth->ensureValidAccessToken($connection);
            $lineItems  = $this->clioApi->fetchLineItems($connection, (string) $invoice->external_invoice_id);

            $items = [];
            foreach ($lineItems as $line) {
                $total = (float) ($line['total'] ?? 0);
                if ($total <= 0) {
                    continue;
                }
                $items[] = [
                    'description'    => (string) ($line['description'] ?: $this->clioLineItemTypeLabel((string) ($line['type'] ?? ''))),
                    'subDescription' => null,
                    'qty'            => 1.0,
                    'rate'           => $total,
                    'amount'         => $total,
                ];
            }

            return $items;
        } catch (Throwable $e) {
            Log::warning('Clio line item fetch for PDF failed, falling back to a single line.', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Clio line items very often have description === null (confirmed on a
     * real invoice) - "ActivityLineItem" as a customer-facing label reads as
     * an internal type name, not a description of what was billed. Only the
     * two types Clio's bill model actually uses are covered; anything else
     * falls back to a generic "Line item".
     */
    private function clioLineItemTypeLabel(string $type): string
    {
        return match ($type) {
            'ActivityLineItem' => 'Professional services',
            'ExpenseLineItem'  => 'Expense',
            default            => 'Line item',
        };
    }

    private function fetchWaveLineItems(Invoice $invoice): array
    {
        try {
            $connection = WaveConnection::query()
                ->where('provider', 'wave')
                ->where('pms_client_id', $invoice->pms_client_id)
                ->first();

            if (! $connection) {
                return [];
            }

            $connection = $this->waveOAuth->ensureValidAccessToken($connection);
            $lineItems  = $this->waveApi->fetchInvoiceLineItems($connection, (string) $invoice->external_invoice_id);

            $items = [];
            foreach ($lineItems as $line) {
                $total = (float) Arr::get($line, 'total.value', 0);
                if ($total <= 0) {
                    continue;
                }
                $items[] = [
                    'description'    => (string) ($line['description'] ?: Arr::get($line, 'product.name', 'Item')),
                    'subDescription' => null,
                    'qty'            => 1.0,
                    'rate'           => $total,
                    'amount'         => $total,
                ];
            }

            return $items;
        } catch (Throwable $e) {
            Log::warning('Wave line item fetch for PDF failed, falling back to a single line.', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function resolveDueDate(Invoice $invoice): ?string
    {
        $raw = $invoice->raw_payload ?? [];

        $dueDate = Arr::get($raw, 'invoice.Invoice.DueDate')
            ?? Arr::get($raw, 'invoice.data.due_at')
            ?? Arr::get($raw, 'invoice.data.dueDate')
            ?? Arr::get($raw, 'trigger.data.due_date');

        if (! is_string($dueDate) || trim($dueDate) === '') {
            return null;
        }

        try {
            return Carbon::parse($dueDate)->format('F j, Y');
        } catch (Throwable) {
            return $dueDate;
        }
    }

    /**
     * Prefers the merchant's registered QuickBooks address (CompanyInfo.CompanyAddr,
     * fetched live) since it's authoritative and always up to date; falls back to
     * cash_discount_details (the "pay by cash/check" instructions a client fills in
     * manually) for non-QuickBooks clients, or if the QBO fetch fails.
     */
    private function resolveMerchantAddress(Invoice $invoice, ?Client $client): ?array
    {
        if ($client && strtolower((string) $invoice->pms_source) === 'quickbooks') {
            $qbAddress = $this->fetchQbCompanyAddress($client);
            if ($qbAddress !== []) {
                return $qbAddress;
            }
        }

        $details = $client?->cash_discount_details;

        if (! is_array($details)) {
            return null;
        }

        $line1 = trim((string) ($details['address'] ?? ''));
        $line2 = trim(implode(', ', array_filter([
            trim((string) ($details['city'] ?? '')),
            trim(implode(' ', array_filter([
                trim((string) ($details['state'] ?? '')),
                trim((string) ($details['zip'] ?? '')),
            ]))),
        ])));

        $lines = array_filter([$line1, $line2]);

        return $lines !== [] ? array_values($lines) : null;
    }

    private function fetchQbCompanyAddress(Client $client): array
    {
        try {
            $connection = QuickBooksConnection::query()
                ->where('provider', 'quickbooks')
                ->where('pms_client_id', $client->pms_client_id)
                ->first();

            if (! $connection) {
                return [];
            }

            $connection = $this->qbOAuth->ensureValidAccessToken($connection);
            $info       = $this->qbApi->fetchCompanyInfo($connection);

            return $this->qboAddressLines(Arr::get($info, 'CompanyInfo.CompanyAddr'));
        } catch (Throwable $e) {
            Log::warning('QuickBooks company address fetch for PDF failed.', [
                'pms_client_id' => $client->pms_client_id,
                'error'         => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Invoice.BillAddr - already present in raw_payload from ingestion, no extra
     * API call needed (unlike the merchant's CompanyAddr, which isn't part of the
     * Invoice payload).
     */
    private function resolveBillingAddress(Invoice $invoice): array
    {
        return $this->qboAddressLines(Arr::get($invoice->raw_payload ?? [], 'invoice.Invoice.BillAddr'));
    }

    /**
     * Invoice.ShipAddr - shipping can differ from billing, so this is kept
     * separate rather than falling back to the billing address.
     */
    private function resolveShippingAddress(Invoice $invoice): array
    {
        return $this->qboAddressLines(Arr::get($invoice->raw_payload ?? [], 'invoice.Invoice.ShipAddr'));
    }

    /**
     * QuickBooks' PhysicalAddress shape (Line1/Line2/City/CountrySubDivisionCode/
     * PostalCode/Country) into the same "array of display lines" format the rest
     * of this file already uses for addresses.
     */
    private function qboAddressLines(mixed $addr): array
    {
        if (! is_array($addr)) {
            return [];
        }

        $line1 = trim((string) ($addr['Line1'] ?? ''));
        $line2 = trim((string) ($addr['Line2'] ?? ''));
        $cityStateZip = trim(implode(', ', array_filter([
            trim((string) ($addr['City'] ?? '')),
            trim(implode(' ', array_filter([
                trim((string) ($addr['CountrySubDivisionCode'] ?? '')),
                trim((string) ($addr['PostalCode'] ?? '')),
            ]))),
        ])));
        $country = trim((string) ($addr['Country'] ?? ''));

        return array_values(array_filter([$line1, $line2, $cityStateZip, $country]));
    }

    /**
     * Invoice.CustomerMemo.value - merchants use this for payment instructions,
     * wire/Zelle details, etc. Shown separately from the generic static notes.
     */
    private function resolveCustomerMemo(Invoice $invoice): ?string
    {
        $memo = Arr::get($invoice->raw_payload ?? [], 'invoice.Invoice.CustomerMemo.value');

        return is_string($memo) && trim($memo) !== '' ? trim($memo) : null;
    }

    private function resolveMerchantContact(?Client $client, ?EmailConfiguration $emailConfig): ?string
    {
        $details = $client?->cash_discount_details;

        $email = trim((string) (
            $emailConfig?->reply_to_email
            ?? (is_array($details) ? ($details['email'] ?? '') : '')
        ));
        $phone = trim((string) (is_array($details) ? ($details['phone'] ?? '') : ''));

        $parts = array_filter([$email, $phone]);

        return $parts !== [] ? implode(' • ', $parts) : null;
    }

    /**
     * recipient_emails is populated by every PMS ingestion service specifically
     * so the payment-link email has somewhere to send to - reliable at
     * payment-link time, unlike $invoice->customer['email'] which (like the
     * customer name) only gets set after payment.
     */
    private function resolveCustomerEmail(Invoice $invoice): ?string
    {
        $email = $invoice->recipient_emails[0] ?? ($invoice->customer['email'] ?? null);

        return is_string($email) && trim($email) !== '' ? trim($email) : null;
    }

    /**
     * Payment terms (e.g. "Net 30") - only confirmed available for QuickBooks
     * (SalesTermRef.name). Zoho has a `terms` field in the same shape but it
     * was empty on the one real invoice checked - included as a best-effort
     * read, not confirmed populated.
     */
    private function resolveTerms(Invoice $invoice): ?string
    {
        $raw = $invoice->raw_payload ?? [];

        $terms = Arr::get($raw, 'invoice.Invoice.SalesTermRef.name')
            ?? Arr::get($raw, 'invoice.invoice.terms');

        return is_string($terms) && trim($terms) !== '' ? trim($terms) : null;
    }

    /**
     * Real tax breakdown - only confirmed available for QuickBooks
     * (TxnTaxDetail). Everyone else (Clio, Wave, Lawcus, and Zoho invoices with
     * no tax applied) omits the tax row entirely rather than show a fabricated
     * "0%" that might just mean "we don't know", not "there is no tax".
     */
    private function resolveTax(Invoice $invoice): array
    {
        $raw = $invoice->raw_payload ?? [];

        $totalTax = Arr::get($raw, 'invoice.Invoice.TxnTaxDetail.TotalTax');
        $percent  = Arr::get($raw, 'invoice.Invoice.TxnTaxDetail.TaxLine.0.TaxLineDetail.TaxPercent');

        if ($totalTax === null || (float) $totalTax <= 0) {
            return ['amount' => null, 'label' => null];
        }

        $label = $percent !== null
            ? 'Sales Tax ('.rtrim(rtrim(number_format((float) $percent, 2), '0'), '.').'%)'
            : 'Sales Tax';

        return ['amount' => (float) $totalTax, 'label' => $label];
    }
}
