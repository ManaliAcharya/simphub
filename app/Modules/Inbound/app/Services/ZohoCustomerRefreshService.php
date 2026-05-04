<?php

namespace Modules\Inbound\Services;

use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\PmsConnection;

/**
 * Re-fetches a Zoho customer record and merges it into the invoice's stored
 * raw_payload so that downstream bank-detail extraction has fresh data.
 *
 * This is called lazily at payment-page load time when account / routing
 * numbers are found to be missing — it does NOT duplicate any ingestion logic.
 */
class ZohoCustomerRefreshService
{
    public function __construct(
        private readonly ZohoApiClient $client,
        private readonly ZohoOAuthService $oauth,
    ) {}

    /**
     * Re-fetch the Zoho customer that belongs to this invoice and patch the
     * invoice's raw_payload.customer with the fresh data.
     *
     * Returns the updated invoice (unsaved) so the caller can decide whether
     * to persist. Returns null when the preconditions are not met (wrong PMS
     * source, missing connection, missing external_client_id, or API failure).
     */
    public function refreshCustomerPayload(Invoice $invoice): ?Invoice
    {
        // Only handle Zoho invoices.
        if ((string) $invoice->pms_source !== 'zoho') {
            return null;
        }

        $externalClientId = trim((string) ($invoice->external_client_id ?? ''));
        if ($externalClientId === '') {
            return null;
        }

        $pmsClientId = trim((string) ($invoice->pms_client_id ?? ''));
        if ($pmsClientId === '') {
            return null;
        }

        // Resolve the Zoho connection for this client (same lookup used during ingestion).
        $connection = PmsConnection::query()
            ->where('provider', 'zoho')
            ->where('pms_client_id', $pmsClientId)
            ->first();

        if (! $connection) {
            return null;
        }

        // Resolve the organization id from the connection meta or stored payload —
        // mirrors the logic in ZohoInvoiceIngestionService::resolveOrganizationId().
        $rawPayload  = (array) ($invoice->raw_payload ?? []);
        $triggerPayload = (array) ($rawPayload['trigger'] ?? []);

        $organizationId = (string) (
            data_get($triggerPayload, 'organization_id')
            ?? data_get($triggerPayload, 'organization.organization_id')
            ?? data_get($triggerPayload, 'data.organization_id')
            ?? data_get($connection->meta, 'organizations_payload.org.0.id')
            ?? data_get($connection->meta, 'organizations.0.organization_id')
            ?? ''
        );

        if ($organizationId === '') {
            return null;
        }

        try {
            $connection      = $this->oauth->ensureValidAccessToken($connection);
            $customerPayload = $this->client->fetchContact($connection, $externalClientId, $organizationId);
        } catch (\Throwable) {
            // Silently swallow — the caller will treat missing details as
            // "payment unavailable" rather than throwing a 500.
            return null;
        }

        if (empty($customerPayload)) {
            return null;
        }

        // Merge the refreshed customer data into the existing raw_payload and
        // return the (unsaved) invoice so the caller can persist if desired.
        $rawPayload['customer'] = $customerPayload;
        $invoice->raw_payload   = $rawPayload;

        return $invoice;
    }
}
