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
}
