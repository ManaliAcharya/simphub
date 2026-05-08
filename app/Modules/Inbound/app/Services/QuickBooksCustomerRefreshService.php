<?php

namespace Modules\Inbound\Services;

use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\QuickBooksConnection;

/**
 * Re-fetches a QuickBooks customer record (including its CustomField entries)
 * and merges the result into the invoice's stored raw_payload so that the
 * downstream bank-detail extraction in PaymentCheckoutService has fresh data.
 *
 * QuickBooks custom fields on the Customer object are shaped like:
 *
 *   { "Name": "Account Number", "Type": "StringType", "StringVal": "123456789" }
 *   { "Name": "Routing Number", "Type": "StringType", "StringVal": "021000021" }
 *
 * These are handled by collectLabeledValuePairs() in PaymentCheckoutService
 * via the Name/StringVal candidate keys added for QB support.
 *
 * Called lazily at payment-page load / submit time when account / routing
 * numbers are found to be missing from the invoice's stored payload.
 */
class QuickBooksCustomerRefreshService
{
    public function __construct(
        private readonly QuickBooksApiClient $client,
        private readonly QuickBooksOAuthService $oauth,
    ) {}

    /**
     * Re-fetch the QuickBooks customer for this invoice and patch
     * raw_payload.customer with the fresh data (including CustomField entries).
     *
     * Returns the updated invoice (unsaved) so the caller decides whether to
     * persist. Returns null when preconditions are not met or the API fails.
     */
    public function refreshCustomerPayload(Invoice $invoice): ?Invoice
    {
        if ((string) $invoice->pms_source !== 'quickbooks') {
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

        $connection = QuickBooksConnection::query()
            ->where('provider', 'quickbooks')
            ->where('pms_client_id', $pmsClientId)
            ->first();

        if (! $connection) {
            return null;
        }

        try {
            $connection      = $this->oauth->ensureValidAccessToken($connection);
            $customerPayload = $this->client->fetchCustomer($connection, $externalClientId);
        } catch (\Throwable) {
            return null;
        }

        if (empty($customerPayload)) {
            return null;
        }

        $rawPayload             = (array) ($invoice->raw_payload ?? []);
        $rawPayload['customer'] = $customerPayload;
        $invoice->raw_payload   = $rawPayload;

        return $invoice;
    }
}
