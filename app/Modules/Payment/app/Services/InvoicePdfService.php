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
use Modules\Inbound\Services\LawcusTrustBalance;
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

    /**
     * Generated at send time from the invoice's current data — nothing is stored.
     */
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

            $html = view('payment::pdf.invoice', array_merge([
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
                'issueDate'        => $this->resolveIssueDate($invoice),
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
                // Lawcus "Show Trust Balance": the matter's trust balance, for information only.
                'trustBalance'     => app(LawcusTrustBalance::class)->formatted($invoice),
            ], $this->clioView($invoice), $this->lawcusView($invoice, $totalAmount)))->render();

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
     * Lawcus' invoice (GET /invoices/{id}, kept in raw_payload) carries its lines.
     */
    private function resolveLineItems(Invoice $invoice): array
    {
        $raw = $invoice->raw_payload ?? [];

        $qbLines = Arr::get($raw, 'invoice.Invoice.Line');
        if (is_array($qbLines)) {
            // Bundles (GroupLineDetail) carry their items in GroupLineDetail.Line — list
            // those items rather than dropping the bundle.
            $qbLines = collect($qbLines)
                ->flatMap(fn ($line) => ($line['DetailType'] ?? null) === 'GroupLineDetail'
                    ? (array) Arr::get($line, 'GroupLineDetail.Line', [])
                    : [$line])
                ->all();

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
            $items = strtoupper((string) $invoice->fund_type) === 'TRUST'
                ? $this->fetchClioTrustLines($invoice)
                : $this->fetchClioLineItems($invoice);
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

        if ($pmsSource === 'lawcus') {
            $items = self::lawcusLineItems((array) Arr::get($raw, 'invoice.data', []), (array) ($raw['activities'] ?? []));
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

    /**
     * A Lawcus invoice's lines as Lawcus lists them: time entries, then flat fees, then
     * expenses. GET /invoices/{id} embeds them as timeentries / flatfees / expenses; each row
     * has date, quantity, rate and total. The client-facing text is description (time entries,
     * flat fees) or public_note (expenses) — never private_note. The activity category
     * (activity_id, named in $activities) fills the Product or Service column. Non-billable
     * lines aren't charged, so they're left out.
     *
     * @param  array<string, string>  $activities  activity_id => name
     * @return list<array{productOrService: string, description: string, subDescription: ?string, qty: float, rate: float, amount: float}>
     */
    public static function lawcusLineItems(array $data, array $activities = []): array
    {
        $groups = ['timeentries' => 'Time entry', 'flatfees' => 'Flat fee', 'expenses' => 'Expense'];
        $items  = [];

        foreach ($groups as $key => $label) {
            foreach ((array) ($data[$key] ?? []) as $line) {
                if (! is_array($line) || filter_var($line['is_non_billable'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }

                $qty    = is_numeric($line['quantity'] ?? null) ? (float) $line['quantity'] : 1.0;
                $rate   = is_numeric($line['rate'] ?? null) ? (float) $line['rate'] : null;
                $amount = is_numeric($line['total'] ?? null)
                    ? (float) $line['total']
                    : round($qty * (float) ($rate ?? $line['amount'] ?? 0), 2);

                $description = trim(html_entity_decode(strip_tags((string) (
                    ($line['description'] ?? null) ?: ($line['public_note'] ?? '')
                ))));
                $activity = isset($line['activity_id']) ? ($activities[(string) $line['activity_id']] ?? null) : null;
                $date     = (string) ($line['date'] ?? '');

                $items[] = [
                    'productOrService' => $activity ?: $label,
                    'description'      => $description !== '' ? $description : ($activity ?: $label),
                    'subDescription'   => $date !== '' && strtotime($date) !== false ? date('m/d/Y', strtotime($date)) : null,
                    'qty'              => $qty,
                    'rate'             => $rate ?? ($qty != 0.0 ? round($amount / $qty, 2) : $amount),
                    'amount'           => $amount,
                ];
            }
        }

        return $items;
    }

    /**
     * A partly paid Lawcus invoice: its lines add up to the invoice total, not to what's
     * still owed, so show the total, what's been paid and the balance due (as for Clio).
     */
    private function lawcusView(Invoice $invoice, float $due): array
    {
        if (strtolower((string) $invoice->pms_source) !== 'lawcus') {
            return [];
        }

        $data  = (array) Arr::get($invoice->raw_payload ?? [], 'invoice.data', []);
        $total = $data['total'] ?? null;

        if (! is_numeric($total) || self::lawcusLineItems($data) === [] || (float) $total - $due < 0.01) {
            return [];
        }

        return [
            'subtotal'     => number_format((float) $total, 2),
            'invoiceTotal' => number_format((float) $total, 2),
            'paidAmount'   => number_format((float) $total - $due, 2),
        ];
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
                // No-charge lines are left off; discount lines (negative) stay so the lines
                // add up to Clio's subtotal.
                if ($total == 0.0) {
                    continue;
                }
                $quantity = $line['quantity'] ?? null;
                $price    = $line['price'] ?? null;

                $items[] = [
                    'productOrService' => $this->formatDate($line['date'] ?? null, 'M j, Y'),
                    'description'      => (string) (($line['description'] ?? null) ?: ($line['note'] ?? null) ?: $this->clioLineItemTypeLabel((string) ($line['type'] ?? ''))),
                    'subDescription'   => null,
                    // Time entries: quantity is hours and price the hourly rate.
                    'qty'              => is_numeric($quantity) ? (float) $quantity : 1.0,
                    'rate'             => is_numeric($price) ? (float) $price : $total,
                    'amount'           => $total,
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

    /** A Clio Trust Request's lines: each matter's requested amount, with the firm's note. */
    private function fetchClioTrustLines(Invoice $invoice): array
    {
        try {
            $connection = ClioConnection::query()
                ->where('provider', 'clio')
                ->where('pms_client_id', $invoice->pms_client_id)
                ->first();

            if (! $connection) {
                return [];
            }

            $lines = $this->clioApi->fetchTrustLineItemsForPdf(
                $this->clioOAuth->ensureValidAccessToken($connection),
                (string) $invoice->external_invoice_id,
            );

            $items = [];
            foreach ($lines as $line) {
                $total = (float) ($line['total'] ?? 0);
                if ($total == 0.0) {
                    continue;
                }

                $matter = trim(implode(' - ', array_filter([
                    (string) Arr::get($line, 'matter.display_number', ''),
                    (string) Arr::get($line, 'matter.description', ''),
                ])));

                $items[] = [
                    'productOrService' => $this->formatDate($line['date'] ?? null, 'M j, Y'),
                    'description'      => $matter !== '' ? 'Trust deposit: '.$matter : 'Trust deposit',
                    'subDescription'   => trim((string) ($line['note'] ?? '')) ?: null,
                    'qty'              => 1.0,
                    'rate'             => $total,
                    'amount'           => $total,
                ];
            }

            return $items;
        } catch (Throwable $e) {
            Log::warning('Clio trust line fetch for PDF failed, falling back to a single line.', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Clio's own figures for the PDF, so it matches what the firm sees in Clio: the bill's
     * subtotal, tax, discounts/interest (whatever reconciles Clio's subtotal + tax to its
     * total), total, what's been paid or applied from trust, and the balance due — which is
     * what the payment link charges. Empty for other PMSs.
     */
    private function clioView(Invoice $invoice): array
    {
        if (strtolower((string) $invoice->pms_source) !== 'clio') {
            return [];
        }

        $bill    = (array) Arr::get($invoice->raw_payload ?? [], 'invoice.data', []);
        $isTrust = strtoupper((string) $invoice->fund_type) === 'TRUST';
        $due     = $invoice->amount_cents / 100;
        $total   = is_numeric($bill['total'] ?? null) ? (float) $bill['total'] : $due;
        // Paid/applied follows the live balance the link charges, so the rows always add up.
        $paid    = max(0.0, round($total - $due, 2));
        $memo    = trim((string) ($bill['memo'] ?? ''));

        $view = [
            'documentLabel'    => $isTrust ? 'Trust Request' : 'Invoice',
            'subject'          => trim((string) ($bill['subject'] ?? '')) ?: null,
            'matterLabel'      => $this->clioMatterLabel($invoice, $bill),
            'firstColumnLabel' => 'Date',
            'qtyLabel'         => $isTrust ? 'Qty' : 'Hours / Qty',
            'customerMemo'     => $memo !== '' ? $memo : null,
            'subtotal'         => number_format($total, 2),
            'taxAmount'        => null,
            'taxLabel'         => null,
            'invoiceTotal'     => number_format($total, 2),
            'paidAmount'       => $paid > 0 ? number_format($paid, 2) : null,
            'totalAmount'      => number_format($due, 2),
        ];

        if ($isTrust || ! is_numeric($bill['sub_total'] ?? null)) {
            return $view;
        }

        $subTotal = (float) $bill['sub_total'];
        $tax      = (float) (is_numeric($bill['tax_sum'] ?? null) ? $bill['tax_sum'] : 0)
            + (float) (is_numeric($bill['secondary_tax_sum'] ?? null) ? $bill['secondary_tax_sum'] : 0);
        $adjust   = round($total - $subTotal - $tax, 2);

        $view['subtotal']  = number_format($subTotal, 2);
        $view['taxAmount'] = $tax > 0 ? number_format($tax, 2) : null;
        $view['taxLabel']  = $tax > 0 ? 'Tax' : null;

        if (abs($adjust) >= 0.01) {
            $view['adjustmentLabel']  = $adjust < 0 ? 'Discount' : 'Interest & Adjustments';
            $view['adjustmentAmount'] = ($adjust < 0 ? '-' : '').number_format(abs($adjust), 2);
        }

        return $view;
    }

    private function clioMatterLabel(Invoice $invoice, array $bill): ?string
    {
        $matter = (array) Arr::get($invoice->raw_payload ?? [], 'matter.data', []);

        if ($matter === []) {
            $matters = (array) ($bill['matters'] ?? []);
            $matter  = count($matters) === 1 && is_array($matters[0] ?? null) ? $matters[0] : [];

            if ($matter === [] && count($matters) > 1) {
                $numbers = array_filter(array_map(fn ($m) => is_array($m) ? (string) ($m['display_number'] ?? '') : '', $matters));

                return $numbers !== [] ? implode(', ', $numbers) : null;
            }
        }

        $label = trim(implode(' - ', array_filter([
            (string) ($matter['display_number'] ?? ''),
            (string) ($matter['description'] ?? ''),
        ])));

        return $label !== '' ? $label : null;
    }

    private function formatDate(mixed $date, string $format): ?string
    {
        if (! is_string($date) || trim($date) === '') {
            return null;
        }

        try {
            return Carbon::parse($date)->format($format);
        } catch (Throwable) {
            return $date;
        }
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

    /**
     * The invoice's own date from the PMS (QuickBooks TxnDate etc.); the date SimpHub
     * received it is only a fallback — it can be later than the due date.
     */
    private function resolveIssueDate(Invoice $invoice): ?string
    {
        $raw = $invoice->raw_payload ?? [];

        $issueDate = Arr::get($raw, 'invoice.Invoice.TxnDate')
            ?? Arr::get($raw, 'invoice.invoice.date')
            ?? Arr::get($raw, 'invoice.data.issued_at')
            ?? Arr::get($raw, 'invoice.data.issue_date')
            ?? Arr::get($raw, 'invoice.data.invoiceDate');

        if (is_string($issueDate) && trim($issueDate) !== '') {
            try {
                return Carbon::parse($issueDate)->format('F j, Y');
            } catch (Throwable) {
                return $issueDate;
            }
        }

        return $invoice->created_at?->format('F j, Y');
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
     * Invoice payload). When a customer has no street address on file, QuickBooks
     * defaults BillAddr.Line1 to the customer's own name instead of leaving it
     * blank — drop it so we don't render a person's name as a mailing address.
     * Falls back to the customer's on-file BillAddr for any City/State/Zip/Country
     * the invoice-level override left blank (QBO lets an invoice override just
     * Line1 without touching the rest), so the PDF never shows a half address.
     */
    private function resolveBillingAddress(Invoice $invoice): array
    {
        $raw = $invoice->raw_payload ?? [];

        return $this->qboAddressLines(
            $this->mergeQboAddress(
                Arr::get($raw, 'invoice.Invoice.BillAddr'),
                Arr::get($raw, 'customer.Customer.BillAddr'),
            ),
            $this->resolveCustomerName($invoice),
        );
    }

    /**
     * Invoice.ShipAddr - shipping can differ from billing, so this is kept
     * separate rather than falling back to the billing address. Unlike BillAddr,
     * ShipAddr isn't observed to get name-defaulted, so no name filtering here.
     * Same on-file fallback as billing for any missing City/State/Zip/Country.
     */
    private function resolveShippingAddress(Invoice $invoice): array
    {
        $raw = $invoice->raw_payload ?? [];

        return $this->qboAddressLines(
            $this->mergeQboAddress(
                Arr::get($raw, 'invoice.Invoice.ShipAddr'),
                Arr::get($raw, 'customer.Customer.ShipAddr'),
            ),
        );
    }

    /**
     * Fills blank City/CountrySubDivisionCode/PostalCode/Country/Line2 on an
     * invoice-level address with the customer's on-file address, while always
     * keeping the invoice's own Line1 (a merchant's per-invoice street override).
     */
    private function mergeQboAddress(mixed $addr, mixed $fallback): ?array
    {
        $addr     = is_array($addr) ? $addr : [];
        $fallback = is_array($fallback) ? $fallback : [];

        if ($addr === [] && $fallback === []) {
            return null;
        }

        $merged = [];
        foreach (['Line1', 'Line2', 'City', 'CountrySubDivisionCode', 'PostalCode', 'Country'] as $field) {
            $value = trim((string) ($addr[$field] ?? ''));
            $merged[$field] = $value !== '' ? $value : trim((string) ($fallback[$field] ?? ''));
        }

        return $merged;
    }

    /**
     * QuickBooks' PhysicalAddress shape (Line1/Line2/City/CountrySubDivisionCode/
     * PostalCode/Country) into the same "array of display lines" format the rest
     * of this file already uses for addresses. $skipLine1IfEquals drops Line1
     * when it's just the customer's name (see resolveBillingAddress above).
     */
    private function qboAddressLines(mixed $addr, ?string $skipLine1IfEquals = null): array
    {
        if (! is_array($addr)) {
            return [];
        }

        $line1 = trim((string) ($addr['Line1'] ?? ''));
        if ($skipLine1IfEquals !== null && $line1 !== '' && strcasecmp($line1, trim($skipLine1IfEquals)) === 0) {
            $line1 = '';
        }

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
