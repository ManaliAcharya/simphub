<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Inbound\Models\LawcusConnection;

class LawcusApiClient
{
    public function baseRequest(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->baseUrl(config('services.lawcus.api_base_url'));
    }

    public function authenticatedRequest(LawcusConnection $connection): PendingRequest
    {
        return $this->baseRequest()->withToken($connection->access_token);
    }

    public function postWebhook(LawcusConnection $connection, array $payload): Response
    {
        return $this->authenticatedRequest($connection)->post('/api/v1/webhooks', $payload);
    }

    public function fetchBill(LawcusConnection $connection, string $externalInvoiceId): array
    {
        $response = $this->authenticatedRequest($connection)
            ->get("/api/v1/invoices/{$externalInvoiceId}");

        if ($response->failed() && Arr::get($response->json(), 'error.type') === 'InvalidFields') {
            Log::warning('Lawcus invoice fetch rejected requested fields, retrying without field filter.', [
                'external_invoice_id' => $externalInvoiceId,
                'error'               => $response->json(),
            ]);

            $response = $this->authenticatedRequest($connection)
                ->get("/api/v1/invoices/{$externalInvoiceId}");
        }

        return $response->throw()->json();
    }

    public function fetchContact(LawcusConnection $connection, string $externalClientId): array
    {
        $response = $this->authenticatedRequest($connection)
            ->get("/api/v1/contacts/{$externalClientId}");

        if ($response->failed() && Arr::get($response->json(), 'error.type') === 'InvalidFields') {
            Log::warning('Lawcus contact fetch rejected requested fields, retrying without field filter.', [
                'external_client_id' => $externalClientId,
                'error'              => $response->json(),
            ]);

            $response = $this->authenticatedRequest($connection)
                ->get("/api/v1/contacts/{$externalClientId}");
        }

        return $response->throw()->json();
    }

    public function fetchBankAccounts(LawcusConnection $connection): array
    {
        $response = $this->authenticatedRequest($connection)
            ->get('/api/v1/bank-accounts')
            ->throw()
            ->json();

        $accounts = $response['data'] ?? $response;
        if (! is_array($accounts)) {
            return [];
        }

        return collect($accounts)
            ->filter(fn ($account) => is_array($account))
            ->map(fn (array $account): array => [
                'account_id'   => (string) ($account['id']   ?? ''),
                'account_name' => (string) ($account['name'] ?? 'Unknown'),
                'account_type' => (string) ($account['type'] ?? ''),
            ])
            ->filter(fn (array $account): bool => $account['account_id'] !== '')
            ->sortBy(fn (array $account) => strtolower($account['account_name']))
            ->values()
            ->all();
    }

    public function recordPayment(LawcusConnection $connection, array $payload): array
    {
        return $this->authenticatedRequest($connection)
            ->post('/api/v1/payments', ['data' => $payload])
            ->throw()
            ->json();
    }

    public function markBillPaid(LawcusConnection $connection, string $billId): array
    {
        return $this->authenticatedRequest($connection)
            ->patch("/api/v1/invoices/{$billId}", ['data' => ['status' => 'paid']])
            ->throw()
            ->json();
    }
}
