<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\QuickBooksConnection;

class QuickBooksApiClient
{
    private function request(QuickBooksConnection $connection): PendingRequest
    {
        $realmId = $connection->realmId();

        if ($realmId === '') {
            throw new \RuntimeException('QuickBooks realmId is not set on this connection.');
        }

        return Http::withToken($connection->access_token)
            ->acceptJson()
            ->asJson()
            ->baseUrl(rtrim(config('services.quickbooks.base_url'), '/')."/v3/company/{$realmId}");
    }

    private function minorVersion(): array
    {
        return ['minorversion' => config('services.quickbooks.minor_version', '65')];
    }

    public function fetchInvoice(QuickBooksConnection $connection, string $invoiceId): array
    {
        return $this->request($connection)
            ->get("/invoice/{$invoiceId}", $this->minorVersion())
            ->throw()
            ->json();
    }

    public function fetchCustomer(QuickBooksConnection $connection, string $customerId): array
    {
        return $this->request($connection)
            ->get("/customer/{$customerId}", $this->minorVersion())
            ->throw()
            ->json();
    }

    public function recordPayment(QuickBooksConnection $connection, array $payload): array
    {
        return $this->request($connection)
            ->post('/payment', array_merge($payload, $this->minorVersion()))
            ->throw()
            ->json();
    }

    public function queryInvoice(QuickBooksConnection $connection, string $invoiceId): array
    {
        $sql = "SELECT * FROM Invoice WHERE Id = '{$invoiceId}'";

        return $this->request($connection)
            ->get('/query', array_merge(['query' => $sql], $this->minorVersion()))
            ->throw()
            ->json();
    }
}
