<?php

namespace Modules\Inbound\Services;

use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\PmsConnection;

/**
 * Re-fetches a Clio contact record (including its custom field values) and
 * merges the result into the invoice's stored raw_payload so that the
 * downstream bank-detail extraction in PaymentCheckoutService has fresh data.
 *
 * Clio's custom fields API returns entries shaped like:
 *
 *   { "field_name": "Account Number", "value": "123456789" }
 *   { "field_name": "Routing Number", "value": "021000021" }
 *
 * These are already handled by collectLabeledValuePairs() in
 * PaymentCheckoutService — no special extraction logic is needed here.
 *
 * Called lazily at payment-page load / submit time when account / routing
 * numbers are found to be missing from the invoice's stored payload.
 */
class ClioCustomerRefreshService
{
    public function __construct(
        private readonly ClioApiClient $client,
    ) {}

    /**
     * Re-fetch the Clio contact that belongs to this invoice and patch
     * raw_payload.customer with the fresh data (including custom fields).
     *
     * Returns the updated invoice (unsaved) so the caller decides whether to
     * persist. Returns null when preconditions are not met (wrong PMS source,
     * missing connection / external_client_id, empty or failed API response).
     */
    public function refreshCustomerPayload(Invoice $invoice): ?Invoice
    {
        // Only handle Clio invoices.
        if ((string) $invoice->pms_source !== 'clio') {
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

        // Resolve the Clio PMS connection for this client.
        $connection = PmsConnection::query()
            ->where('provider', 'clio')
            ->where('pms_client_id', $pmsClientId)
            ->first();

        if (! $connection) {
            return null;
        }

        try {
            // fetchContact must request custom_field_values so that
            // account / routing number entries are present in the response.
            // The ClioApiClient is responsible for including
            // ?fields=id,first_name,last_name,custom_field_values{field_name,value}
            // (or equivalent) in the request URL.
            $customerPayload = $this->client->fetchContact($connection, $externalClientId);
        } catch (\Throwable) {
            // Silently swallow — the caller treats missing bank details as
            // "payment unavailable" rather than propagating a 500.
            return null;
        }

        if (empty($customerPayload)) {
            return null;
        }

        // Merge the refreshed Clio contact data into the existing raw_payload.
        // We store it under the same 'customer' key that Zoho uses so the
        // PMS-agnostic extraction in resolvePayaBankDetails() works for both.
        $rawPayload             = (array) ($invoice->raw_payload ?? []);
        $rawPayload['customer'] = $customerPayload;
        $invoice->raw_payload   = $rawPayload;

        return $invoice;
    }
}