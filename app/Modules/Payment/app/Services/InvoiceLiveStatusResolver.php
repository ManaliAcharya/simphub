<?php

namespace Modules\Payment\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\ClioConnection;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Models\QuickBooksConnection;
use Modules\Inbound\Models\WaveConnection;
use Modules\Inbound\Services\ClioApiClient;
use Modules\Inbound\Services\ClioOAuthService;
use Modules\Inbound\Services\QuickBooksApiClient;
use Modules\Inbound\Services\QuickBooksOAuthService;
use Modules\Inbound\Services\WaveApiClient;
use Modules\Inbound\Services\WaveOAuthService;
use Modules\Inbound\Services\ZohoApiClient;
use Modules\Inbound\Services\ZohoOAuthService;

/**
 * Read-only live payment-status check used by the invoice reminder job. Deliberately
 * does not go through InboundInvoiceProcessor's ingestion pipeline — that would also
 * trigger the resend-on-update side effect, conflating two independently-toggleable
 * features. Supports Clio, Zoho, Wave, QuickBooks only (see plan for scope).
 */
class InvoiceLiveStatusResolver
{
    public function __construct(
        private readonly ClioOAuthService $clioOAuth,
        private readonly ClioApiClient $clioApi,
        private readonly ZohoOAuthService $zohoOAuth,
        private readonly ZohoApiClient $zohoApi,
        private readonly WaveOAuthService $waveOAuth,
        private readonly WaveApiClient $waveApi,
        private readonly QuickBooksOAuthService $quickBooksOAuth,
        private readonly QuickBooksApiClient $quickBooksApi,
    ) {}

    /**
     * @return string 'paid' | 'unpaid' | 'gone' | 'unknown'
     */
    public function resolve(Invoice $invoice): string
    {
        try {
            $balance = match ((string) $invoice->pms_source) {
                'clio'       => $this->resolveClioBalance($invoice),
                'zoho'       => $this->resolveZohoBalance($invoice),
                'wave'       => $this->resolveWaveBalance($invoice),
                'quickbooks' => $this->resolveQuickBooksBalance($invoice),
                default      => null,
            };
        } catch (RequestException $e) {
            return $e->response->status() === 404 ? 'gone' : 'unknown';
        } catch (ConnectionException) {
            return 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }

        if ($balance === null) {
            return 'unknown';
        }

        if ($balance === 'gone') {
            return 'gone';
        }

        return ((float) $balance) <= 0.0 ? 'paid' : 'unpaid';
    }

    private function resolveClioBalance(Invoice $invoice): float|string|null
    {
        $connection = ClioConnection::query()
            ->where('provider', 'clio')
            ->where('pms_client_id', $invoice->pms_client_id)
            ->first();

        if (! $connection) {
            return null;
        }

        $connection = $this->clioOAuth->ensureValidAccessToken($connection);
        $payload    = $this->clioApi->fetchBill($connection, $invoice->external_invoice_id);
        $data       = Arr::get($payload, 'data', $payload);

        return (float) (Arr::get($data, 'balance') ?? Arr::get($data, 'total', 0));
    }

    private function resolveZohoBalance(Invoice $invoice): float|string|null
    {
        $connection = PmsConnection::query()
            ->where('provider', 'zoho')
            ->where('pms_client_id', $invoice->pms_client_id)
            ->first();

        if (! $connection) {
            return null;
        }

        $connection     = $this->zohoOAuth->ensureValidAccessToken($connection);
        $organizationId = (string) data_get($connection->meta, 'default_organization_id', '');

        if ($organizationId === '') {
            return null;
        }

        $payload = $this->zohoApi->fetchBill($connection, $invoice->external_invoice_id, $organizationId);
        $data    = Arr::get($payload, 'bill') ?? Arr::get($payload, 'invoice') ?? $payload;

        return (float) (Arr::get($data, 'balance') ?? Arr::get($data, 'total', 0));
    }

    private function resolveWaveBalance(Invoice $invoice): float|string|null
    {
        $connection = WaveConnection::query()
            ->where('provider', 'wave')
            ->where('pms_client_id', $invoice->pms_client_id)
            ->first();

        if (! $connection) {
            return null;
        }

        $connection  = $this->waveOAuth->ensureValidAccessToken($connection);
        $invoiceData = $this->waveApi->findInvoiceByWebhookId($connection, $invoice->external_invoice_id);

        if (empty($invoiceData)) {
            return 'gone';
        }

        return (float) Arr::get($invoiceData, 'amountDue.value', 0);
    }

    private function resolveQuickBooksBalance(Invoice $invoice): float|string|null
    {
        $connection = QuickBooksConnection::query()
            ->where('provider', 'quickbooks')
            ->where('pms_client_id', $invoice->pms_client_id)
            ->first();

        if (! $connection) {
            return null;
        }

        $connection = $this->quickBooksOAuth->ensureValidAccessToken($connection);
        $payload    = $this->quickBooksApi->fetchInvoice($connection, $invoice->external_invoice_id);
        $data       = Arr::get($payload, 'Invoice', $payload);

        return (float) (Arr::get($data, 'Balance') ?? Arr::get($data, 'TotalAmt', 0));
    }
}
