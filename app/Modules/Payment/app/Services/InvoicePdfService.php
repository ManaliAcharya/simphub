<?php

namespace Modules\Payment\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\Client;
use Throwable;

/**
 * Generates a PDF for an invoice from our own data, the same way for every
 * PMS (Clio, Zoho, QuickBooks, Wave, Lawcus) - rather than depending on each
 * PMS's own (inconsistent, sometimes unavailable) invoice/bill PDF export.
 * Never throws: a PDF failure must not block the payment-link email itself.
 */
class InvoicePdfService
{
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

            $html = view('payment::pdf.invoice', [
                'logoUrl'       => $logoUrl,
                'faviconUri'    => $this->dataUri(file_get_contents(public_path('images/logo/simphub-favicon.jpeg')), 'image/jpeg'),
                'merchantName'  => $client?->client_name,
                'customerName'  => $this->resolveCustomerName($invoice),
                'invoiceNumber' => (string) ($invoice->invoice_number ?? $invoice->external_invoice_id ?? ''),
                'issueDate'     => $invoice->created_at?->format('F j, Y'),
                'dueDate'       => $this->resolveDueDate($invoice),
                'currency'      => $invoice->currency ?? 'USD',
                'amount'        => number_format($invoice->amount_cents / 100, 2),
                'paymentUrl'    => $paymentUrl,
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

    private function resolveDueDate(Invoice $invoice): ?string
    {
        $raw = $invoice->raw_payload ?? [];

        $dueDate = Arr::get($raw, 'invoice.Invoice.DueDate')
            ?? Arr::get($raw, 'invoice.data.due_at')
            ?? Arr::get($raw, 'invoice.data.dueDate')
            ?? Arr::get($raw, 'trigger.data.due_date');

        return is_string($dueDate) && trim($dueDate) !== '' ? $dueDate : null;
    }
}
