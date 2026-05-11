<?php

namespace Modules\Inbound\Services;

use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\PmsConnection;

class LawcusCustomerRefreshService
{
    public function __construct(
        private readonly LawcusApiClient $client,
    ) {}

    /**
     * Re-fetch the Lawcus contact for this invoice and patch raw_payload.customer
     * with fresh data (including custom field values for account/routing numbers).
     *
     * Returns the updated invoice (unsaved) on success, null when preconditions
     * are not met or the API call fails.
     */
    public function refreshCustomerPayload(Invoice $invoice): ?Invoice
    {
        if ((string) $invoice->pms_source !== 'lawcus') {
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

        $connection = PmsConnection::query()
            ->where('provider', 'lawcus')
            ->where('pms_client_id', $pmsClientId)
            ->first();

        if (! $connection) {
            return null;
        }

        try {
            $customerPayload = $this->client->fetchContact($connection, $externalClientId);
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
