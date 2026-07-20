<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;
use Modules\Inbound\Models\QuickBooksConnection;

class QuickBooksEmailResolver
{
    public function __construct(
        private readonly QuickBooksApiClient $client,
    ) {}

    /**
     * Resolve recipient emails from a live QuickBooks invoice payload, fetching the
     * linked customer fresh so callers never fall back to a stale cached address.
     */
    public function resolve(QuickBooksConnection $connection, array $invoicePayload): array
    {
        $invoiceData = Arr::get($invoicePayload, 'Invoice', $invoicePayload);

        $customerId = (string) (
            Arr::get($invoiceData, 'CustomerRef.value')
            ?? Arr::get($invoiceData, 'CustomerRef.name')
            ?? ''
        );

        $customerPayload = $this->fetchCustomerPayload($connection, $customerId);
        $customerData     = Arr::get($customerPayload, 'Customer', $customerPayload);

        $emails = array_values(array_unique(array_filter(array_map(
            static fn ($e) => is_string($e) ? trim($e) : null,
            [
                Arr::get($invoiceData, 'BillEmail.Address'),
                Arr::get($invoiceData, 'ShipEmail.Address'),
                Arr::get($customerData, 'PrimaryEmailAddr.Address'),
            ]
        ))));

        return [
            'emails'           => $emails,
            'customer_payload' => $customerPayload,
        ];
    }

    private function fetchCustomerPayload(QuickBooksConnection $connection, string $customerId): array
    {
        if (trim($customerId) === '') {
            return [];
        }

        try {
            return $this->client->fetchCustomer($connection, $customerId);
        } catch (\Throwable) {
            return [];
        }
    }
}
