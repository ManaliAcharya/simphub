<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Inbound\Models\ClioConnection;
use RuntimeException;

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

    public function deleteWebhook(ClioConnection $connection, string $webhookId): Response
    {
        return $this->authenticatedRequest($connection)->delete("/api/v4/webhooks/{$webhookId}.json");
    }

    /**
     * Identifies which Clio firm/account this connection belongs to, so
     * ClioConnector can block the same firm being connected to more than one
     * client (mirrors WaveApiClient::fetchBusinessId()'s caching-in-meta
     * pattern). NOTE: /api/v4/users/who_am_i.json is not something we've
     * confirmed against Clio's docs the way the other endpoints in this file
     * were - verify against a live connect attempt before trusting this.
     */
    public function fetchAccountId(ClioConnection $connection): string
    {
        $cached = Arr::get($connection->meta ?? [], 'account_id');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->authenticatedRequest($connection)
            ->get('/api/v4/users/who_am_i.json', [
                'fields' => 'id,name,account{id,name}',
            ]);

        if ($response->failed() && Arr::get($response->json(), 'error.type') === 'InvalidFields') {
            $response = $this->authenticatedRequest($connection)->get('/api/v4/users/who_am_i.json');
        }

        $data = $response->throw()->json();
        $accountId = (string) Arr::get($data, 'data.account.id', '');

        if ($accountId === '') {
            throw new RuntimeException('Clio API did not return an account id for this connection.');
        }

        $connection->forceFill(['meta' => array_merge($connection->meta ?? [], ['account_id' => $accountId])])->save();

        return $accountId;
    }

    /**
     * Field sets tried in order. `kind` tells a Trust Request (a bill of kind trust) from a
     * regular invoice and `matters` names the matter it was requested for; if Clio rejects
     * them we fall back to the fields that have always worked, and only then to no filter.
     */
    private const BILL_FIELD_SETS = [
        'id,number,total,balance,state,kind,matters{id},client{id,name,primary_email_address}',
        'id,number,total,balance,state,client{id,name,primary_email_address}',
    ];

    public function fetchBill(ClioConnection $connection, string $externalInvoiceId): array
    {
        foreach (self::BILL_FIELD_SETS as $fields) {
            $response = $this->authenticatedRequest($connection)
                ->get("/api/v4/bills/{$externalInvoiceId}.json", ['fields' => $fields]);

            if (! ($response->failed() && Arr::get($response->json(), 'error.type') === 'InvalidFields')) {
                return $response->throw()->json();
            }

            Log::warning('Clio bill fetch rejected requested fields, retrying with fewer.', [
                'external_invoice_id' => $externalInvoiceId,
                'fields' => $fields,
                'error' => $response->json(),
            ]);
        }

        return $this->authenticatedRequest($connection)
            ->get("/api/v4/bills/{$externalInvoiceId}.json")
            ->throw()
            ->json();
    }

    /**
     * Whether Clio lists this bill under the documented `type=trust` bill filter — the
     * fallback check when the bill itself didn't return its `kind`.
     */
    public function isTrustBill(ClioConnection $connection, string $billId): bool
    {
        $query = http_build_query(['type' => 'trust', 'fields' => 'id']).'&ids[]='.rawurlencode($billId);

        $bills = (array) ($this->authenticatedRequest($connection)
            ->get("/api/v4/bills.json?{$query}")
            ->throw()
            ->json('data') ?? []);

        return collect($bills)->contains(fn ($bill) => is_array($bill) && (string) ($bill['id'] ?? '') === $billId);
    }

    /** A Trust Request's per-matter amounts. */
    public function fetchTrustLineItems(ClioConnection $connection, string $billId): array
    {
        return (array) ($this->authenticatedRequest($connection)
            ->get('/api/v4/trust_line_items.json', [
                'bill_id' => $billId,
                'fields'  => 'id,total,matter{id}',
                'limit'   => 200,
            ])
            ->throw()
            ->json('data') ?? []);
    }

    /**
     * Transactions on one bank account for one client created since a time — used to spot a
     * deposit we already made.
     */
    public function fetchBankTransactions(ClioConnection $connection, string $bankAccountId, string $clientId, string $createdSince): array
    {
        return (array) ($this->authenticatedRequest($connection)
            ->get('/api/v4/bank_transactions.json', [
                'bank_account_id' => $bankAccountId,
                'client_id'       => $clientId,
                'created_since'   => $createdSince,
                'fields'          => 'id,amount,description',
                'limit'           => 200,
            ])
            ->throw()
            ->json('data') ?? []);
    }

    /** Records a deposit into a Clio bank account (e.g. the firm's trust account for a client/matter). */
    public function recordBankTransaction(ClioConnection $connection, array $payload): array
    {
        return $this->authenticatedRequest($connection)
            ->post('/api/v4/bank_transactions.json', ['data' => $payload])
            ->throw()
            ->json();
    }

    public function updateWebhookExpiry(ClioConnection $connection, string $webhookId, string $expiresAt): Response
    {
        return $this->authenticatedRequest($connection)
            ->patch("/api/v4/webhooks/{$webhookId}.json", ['data' => ['expires_at' => $expiresAt]]);
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

        $trustIds = $this->fetchTrustBankAccountIds($connection);

        return collect($accounts)
            ->filter(fn ($account) => is_array($account))
            ->map(fn (array $account): array => [
                'account_id'   => (string) ($account['id']   ?? ''),
                'account_name' => (string) ($account['name'] ?? 'Unknown'),
                'account_type' => (string) ($account['type'] ?? ''),
                'is_trust'     => $trustIds !== null
                    ? in_array((string) ($account['id'] ?? ''), $trustIds, true)
                    : str_contains(strtolower((string) ($account['type'] ?? '')), 'trust'),
            ])
            ->filter(fn (array $account): bool => $account['account_id'] !== '')
            ->sortBy(fn (array $account) => strtolower($account['account_name']))
            ->values()
            ->all();
    }

    /**
     * Ids of the firm's trust accounts, from Clio's documented `type=TrustAccount` filter —
     * the account's own `type` text isn't documented ("Trust" in the Clio UI). Null when the
     * filter call fails, so the caller falls back to the type text.
     */
    private function fetchTrustBankAccountIds(ClioConnection $connection): ?array
    {
        $response = $this->authenticatedRequest($connection)
            ->get('/api/v4/bank_accounts.json', ['type' => 'TrustAccount', 'fields' => 'id']);

        if ($response->failed()) {
            Log::warning('Clio trust bank account lookup failed; falling back to the account type text.', [
                'status' => $response->status(),
            ]);

            return null;
        }

        return collect((array) ($response->json('data') ?? []))
            ->map(fn ($account) => is_array($account) ? (string) ($account['id'] ?? '') : '')
            ->filter(fn (string $id) => $id !== '')
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
