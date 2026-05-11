<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Inbound\Models\ClioConnection;

class ClioApiClient
{
    public function baseRequest(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->baseUrl(config('services.clio.api_base_url'));
            //->withHeaders([
              //  'X-API-VERSION' => (string) config('services.clio.api_version', '4'),
            //]);
    }

    public function authenticatedRequest(ClioConnection $connection): PendingRequest
    {
        return $this->baseRequest()->withToken($connection->access_token);
    }

    public function postWebhook(ClioConnection $connection, array $payload): Response
    {
        return $this->authenticatedRequest($connection)->post('/api/v4/webhooks', $payload);
    }

    public function fetchBill(ClioConnection $connection, string $externalInvoiceId): array
    {
        //"/api/v4/webhooks.json?fields=id,url,events,status"
        //https://paymentmiddleware.myreporthub.dev/api/v1/inbound/webhooks/clio?pms_client_id=c50d4823-40c9-4167-a4a4-db44aa7deb21
        $response = $this->authenticatedRequest($connection)
            ->get("/api/v4/bills/{$externalInvoiceId}.json" , [
                'fields' => 'id,number,total,balance,client{id,name,primary_email_address}'
            ]);

        if ($response->failed() && Arr::get($response->json(), 'error.type') === 'InvalidFields') {
            Log::warning('Clio bill fetch rejected requested fields, retrying without field filter.', [
                'external_invoice_id' => $externalInvoiceId,
                'error' => $response->json(),
            ]);

            $response = $this->authenticatedRequest($connection)
                ->get("/api/v4/bills/{$externalInvoiceId}.json");
        }

        return $response->throw()->json();
    }

    public function fetchContact(ClioConnection $connection, string $externalClientId): array
    {
        $response = $this->authenticatedRequest($connection)
            ->get("/api/v4/contacts/{$externalClientId}.json", [
                'fields' => 'id,name,first_name,last_name,primary_email_address,custom_field_values{field_name,value}',
            ]);

        if ($response->failed() && Arr::get($response->json(), 'error.type') === 'InvalidFields') {
            Log::warning('Clio contact fetch rejected requested fields, retrying without field filter.', [
                'external_client_id' => $externalClientId,
                'error' => $response->json(),
            ]);

            $response = $this->authenticatedRequest($connection)
                ->get("/api/v4/contacts/{$externalClientId}.json");
        }

        return $response->throw()->json();
    }

    public function fetchBankAccounts(ClioConnection $connection): array
    {
        $response = $this->authenticatedRequest($connection)
            ->get('/api/v4/bank_accounts.json', ['fields' => 'id,name,type'])
            ->throw()
            ->json();

        $accounts = $response['data'] ?? [];
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

    public function fetchBillForSync(ClioConnection $connection, string $billId): array
    {
        $response = $this->authenticatedRequest($connection)
            ->get("/api/v4/bills/{$billId}.json", [
                'fields' => 'id,number,total,balance,state,available_state_transitions',
            ]);

        if ($response->failed() && Arr::get($response->json(), 'error.type') === 'InvalidFields') {
            $response = $this->authenticatedRequest($connection)
                ->get("/api/v4/bills/{$billId}.json");
        }

        return $response->throw()->json();
    }

    public function fetchLineItems(ClioConnection $connection, string $billId): array
    {
        $response = $this->authenticatedRequest($connection)
            ->get('/api/v4/line_items.json', [
                'bill_id' => $billId,
                'fields'  => 'id,total,balance,description,type',
            ])
            ->throw()
            ->json();

        return $response['data'] ?? [];
    }

    public function recordLineItemPayment(ClioConnection $connection, array $payload): array
    {
        return $this->authenticatedRequest($connection)
            ->post('/api/v4/line_item_payments.json', ['data' => $payload])
            ->throw()
            ->json();
    }

    public function markBillPaid(ClioConnection $connection, string $billId): array
    {
        return $this->authenticatedRequest($connection)
            ->patch("/api/v4/bills/{$billId}.json", ['data' => ['state' => 'paid']])
            ->throw()
            ->json();
    }

    public function transitionBillState(ClioConnection $connection, string $billId, string $state): array
    {
        return $this->authenticatedRequest($connection)
            ->patch("/api/v4/bills/{$billId}.json", ['data' => ['state' => $state]])
            ->throw()
            ->json();
    }
}
