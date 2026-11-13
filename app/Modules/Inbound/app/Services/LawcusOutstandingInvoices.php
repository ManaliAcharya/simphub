<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\LawcusConnection;
use Throwable;

/**
 * "Pay all outstanding" on the payment page of a Lawcus invoice. When the firm turned on
 * the invoice's "show outstanding invoices" toggle, the payer can pay either this invoice
 * or everything the same Lawcus client still owes — this invoice plus their other unpaid
 * invoices across all matters, read live from the accounts-receivable report.
 *
 * Trust Requests never count (they go to the IOLTA account, and the report leaves them
 * out), nor do invoices already paid through us but not yet recorded in Lawcus.
 */
class LawcusOutstandingInvoices
{
    public function __construct(
        private readonly LawcusApiClient $api,
        private readonly LawcusOAuthService $oauth,
    ) {}

    /** Whether this invoice can offer paying all outstanding, before asking Lawcus. */
    public static function offered(Invoice $invoice): bool
    {
        $data = (array) Arr::get((array) ($invoice->raw_payload ?? []), 'invoice.data', []);

        return $invoice->pms_source === 'lawcus'
            && strtoupper((string) $invoice->fund_type) !== 'TRUST'
            && filter_var($data['show_outstanding_invoices'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && is_numeric($data['client_id'] ?? null);
    }

    /**
     * The client's other unpaid invoices and the total with this one, or null when there's
     * nothing more to offer (toggle off, no other invoices, or Lawcus can't be reached —
     * the page then simply offers this invoice alone).
     *
     * @return array{invoices: list<array{external_invoice_id: string, invoice_number: ?string, amount_cents: int, due_date: ?string, matter_id: ?int}>, total_cents: int}|null
     */
    public function for(Invoice $invoice): ?array
    {
        if (! self::offered($invoice)) {
            return null;
        }

        $clientId = (string) Arr::get((array) $invoice->raw_payload, 'invoice.data.client_id');

        $connection = LawcusConnection::query()
            ->where('provider', 'lawcus')
            ->where('pms_client_id', $invoice->pms_client_id)
            ->latest('created_at')
            ->first();

        if (! $connection) {
            return null;
        }

        try {
            $rows = $this->api->fetchOutstandingInvoices($this->oauth->ensureValidAccessToken($connection));
        } catch (Throwable $e) {
            Log::warning('Lawcus outstanding invoices could not be loaded; offering this invoice only', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }

        $others = collect($rows)
            ->filter(fn (array $row) => (string) ($row['client_id'] ?? '') === $clientId
                && (string) $row['id'] !== (string) $invoice->external_invoice_id
                && strtoupper((string) ($row['type'] ?? '')) !== 'TRUST'
                && is_numeric($row['amount_due'] ?? null)
                && (float) $row['amount_due'] > 0);

        // Paid through us already; Lawcus may not show it yet if recording it failed.
        $paidHere = Invoice::query()
            ->where('pms_source', 'lawcus')
            ->where('pms_client_id', $invoice->pms_client_id)
            ->whereIn('external_invoice_id', $others->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->where('status', 'PAID')
            ->pluck('external_invoice_id')
            ->all();

        $invoices = $others
            ->reject(fn (array $row) => in_array((string) $row['id'], $paidHere, true))
            ->map(fn (array $row) => [
                'external_invoice_id' => (string) $row['id'],
                'invoice_number'      => isset($row['number']) ? (string) $row['number'] : null,
                'amount_cents'        => (int) round((float) $row['amount_due'] * 100),
                'due_date'            => isset($row['due_date']) ? substr((string) $row['due_date'], 0, 10) : null,
                'matter_id'           => is_numeric($row['matter_id'] ?? null) ? (int) $row['matter_id'] : null,
            ])
            ->sortBy(fn (array $row) => [$row['due_date'] ?? '9999-12-31', $row['invoice_number'] ?? ''])
            ->values()
            ->all();

        if ($invoices === []) {
            return null;
        }

        return [
            'invoices'    => $invoices,
            'total_cents' => (int) $invoice->amount_cents + array_sum(array_column($invoices, 'amount_cents')),
        ];
    }
}
