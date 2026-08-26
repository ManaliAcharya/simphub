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

    public function fetchContact(PmsConnection $connection, string $contactId, string $organizationId): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->get("/contacts/{$contactId}", [
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }

    public function createWebhook(PmsConnection $connection, string $organizationId, array $payload): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->post('/settings/webhooks', [
                ...$payload,
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }

    public function fetchWebhooks(PmsConnection $connection, string $organizationId): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->get('/settings/webhooks', [
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }

    public function updateWebhook(PmsConnection $connection, string $webhookId, string $organizationId, array $payload): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->put("/settings/webhooks/{$webhookId}", [
                ...$payload,
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }

    public function fetchWorkflows(PmsConnection $connection, string $organizationId): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->get('/settings/workflows', [
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }

    public function fetchWorkflow(PmsConnection $connection, string $workflowId, string $organizationId): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->get("/settings/workflows/{$workflowId}", [
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }

    public function updateWorkflow(PmsConnection $connection, string $workflowId, string $organizationId, array $payload): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->put("/settings/workflows/{$workflowId}", [
                ...$payload,
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }

    public function createWorkflow(PmsConnection $connection, string $organizationId, array $payload): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->post('/settings/workflows', [
                ...$payload,
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }

    public function recordInvoicePayment(
        PmsConnection $connection,
        string $organizationId,
        array $payload
    ): array {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->post('/customerpayments', [
                ...$payload,
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }

    public function fetchPaymentAccounts(PmsConnection $connection, string $organizationId): array
    {
        $response = $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->get('/chartofaccounts', [
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();

        $accounts = $response['chartofaccounts'] ?? [];
        if (! is_array($accounts)) {
            return [];
        }

        return collect($accounts)
            ->filter(fn ($account) => is_array($account))
            ->map(fn (array $account): array => [
                'account_id' => (string) ($account['account_id'] ?? ''),
                'account_name' => (string) ($account['account_name'] ?? 'Unknown account'),
                'account_type' => (string) ($account['account_type'] ?? ''),
            ])
            ->filter(fn (array $account): bool => $account['account_id'] !== '')
            ->sortBy(fn (array $account) => strtolower($account['account_name']))
            ->values()
            ->all();
    }
}
