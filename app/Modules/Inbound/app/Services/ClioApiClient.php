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

    /**
     * Clio has no raw PDF export for bills - this returns the same pre-rendered,
     * themed HTML (with embedded CSS) Clio's own UI shows, for converting to a
     * PDF attachment ourselves. Returns null if Clio has nothing to preview yet
     * (e.g. a brand new draft) rather than throwing, since a missing PDF should
     * never block the payment-link email.
     */
    public function fetchBillPreviewHtml(ClioConnection $connection, string $billId): ?string
    {
        // Deliberately bypasses baseRequest()/authenticatedRequest() - this
        // resource serves raw HTML, not JSON, despite the .json URL suffix, and
        // 406s on both `Accept: application/json` and an explicit `Accept:
        // text/html` override, which points at asJson()'s Content-Type header
        // (sent even on this bodyless GET) being what Clio actually rejects.
        $response = Http::withToken($connection->access_token)
            ->baseUrl(config('services.clio.api_base_url'))
            ->get("/api/v4/bills/{$billId}/preview.json");

        if ($response->failed()) {
            Log::warning('Clio bill preview fetch failed.', [
                'bill_id' => $billId,
                'status' => $response->status(),
                'error' => $response->header('Content-Type') === 'application/json'
                    ? $response->json()
                    : $response->body(),
            ]);

            return null;
        }

        $html = $response->body();

        return trim($html) !== '' ? $html : null;
    }

    public function fetchBill(ClioConnection $connection, string $externalInvoiceId): array
    {
        //"/api/v4/webhooks.json?fields=id,url,events,status"
        //https://paymentmiddleware.myreporthub.dev/api/v1/inbound/webhooks/clio?pms_client_id=c50d4823-40c9-4167-a4a4-db44aa7deb21
        $response = $this->authenticatedRequest($connection)
            ->get("/api/v4/bills/{$externalInvoiceId}.json" , [
                'fields' => 'id,number,total,balance,state,client{id,name,primary_email_address}'
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
                'fields'  => 'id,total,description,type',
            ]);

        if ($response->failed() && Arr::get($response->json(), 'error.type') === 'InvalidFields') {
            $response = $this->authenticatedRequest($connection)
                ->get('/api/v4/line_items.json', ['bill_id' => $billId]);
        }

        return $response->throw()->json()['data'] ?? [];
    }

    public function recordLineItemPayment(ClioConnection $connection, array $payload): array
    {
        return $this->authenticatedRequest($connection)
            ->post('/api/v4/line_item_payments.json', ['data' => $payload])
            ->throw()
            ->json();
    }

    /**
     * Records a bill-level payment via Clio's Payments API. `line_item_payments`
     * (above) 404s — Clio's actual API has no such resource; a Payment record
     * with a `bill_payments` array (bill id + amount) is the real mechanism.
     */
    public function recordPayment(ClioConnection $connection, array $payload): array
    {
        return $this->authenticatedRequest($connection)
            ->post('/api/v4/payments.json', ['data' => $payload])
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
