<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\PmsConnection;

class ZohoApiClient
{
    public function baseRequest(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->baseUrl(config('services.zoho.api_base_url'));
    }

    public function authenticatedRequest(PmsConnection $connection): PendingRequest
    {
        return $this->baseRequest()->withHeaders([
            'Authorization' => 'Zoho-oauthtoken '.$connection->access_token,
        ]);
    }

    public function fetchOrganizations(PmsConnection $connection): array
    {
        return $this->authenticatedRequest($connection)
            ->get('/org')
            ->throw()
            ->json();
    }

    public function fetchBill(PmsConnection $connection, string $billId, string $organizationId): array
    {
        return $this->authenticatedRequest($connection)
            ->get("/bills/{$billId}", [
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }
}
