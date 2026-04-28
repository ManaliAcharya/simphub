<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\PmsConnection;

class ZohoApiClient
{
    public function baseRequest(?string $url = null): PendingRequest
    {
        $baseUrl = $url ?? config('services.zoho.api_base_url');

        return Http::acceptJson()
            ->asJson()
            ->baseUrl($baseUrl);
    }

    public function authenticatedRequest(PmsConnection $connection, ?string $url = null): PendingRequest
    {        
        return $this->baseRequest($url)->withHeaders([
            'Authorization' => 'Zoho-oauthtoken '.$connection->access_token,
        ]);
    }

    public function fetchOrganizations(PmsConnection $connection): array
    {
        $invoiceUrl = config('services.zoho.book_base_url');
        return $this->authenticatedRequest($connection, $invoiceUrl)
            ->get('/organizations')
            ->throw()
            ->json();    
    }

    public function fetchBill(PmsConnection $connection, string $billId, string $organizationId): array
    {
        $invoiceUrl = config('services.zoho.invoice_base_url');

        return $this->authenticatedRequest($connection, $invoiceUrl)
            ->get("/invoices/{$billId}", [
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }
}
