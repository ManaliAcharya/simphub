<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\PmsConnection;

class ZohoApiClient
{
    public function __construct(
        private readonly ZohoRegionResolver $regions,
    ) {}

    public function baseRequest(string $url): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->baseUrl($url);
    }

    public function authenticatedRequest(PmsConnection $connection, string $url): PendingRequest
    {
        return $this->baseRequest($url)->withHeaders([
            'Authorization' => 'Zoho-oauthtoken '.$connection->access_token,
        ]);
    }

    public function fetchOrganizations(PmsConnection $connection): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->get('/organizations')
            ->throw()
            ->json();
    }

    public function fetchBill(PmsConnection $connection, string $billId, string $organizationId): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->get("/invoices/{$billId}", [
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }
}
