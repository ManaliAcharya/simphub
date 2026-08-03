<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\WaveConnection;
use RuntimeException;

use Illuminate\Support\Facades\Log;
class WaveApiClient
{
    private function graphqlEndpoint(): string
    {
        return rtrim(config('services.wave.graphql_url', 'https://gql.waveapps.com/graphql/public'), '/');
    }

    // Wave's business(id:) expects a Relay global ID: base64("Business:{uuid}").
    // fetchBusinessId() stores the plain UUID for webhook matching — re-encode here before GraphQL use.
    private function toBusinessRelayId(string $businessId): string
    {
        $decoded = base64_decode($businessId, true);
        if ($decoded !== false && str_starts_with($decoded, 'Business:')) {
            return $businessId; // already a Relay ID
        }
        return base64_encode('Business:' . $businessId);
    }

    private function graphqlToken(?WaveConnection $connection = null): string
    {
        $token = config('services.wave.full_access_token') ?: $connection?->access_token;

        if (! $token) {
            throw new RuntimeException('No Wave API token available. Set WAVE_FULL_ACCESS_TOKEN in .env.');
        }

        return $token;
    }

    private function graphqlPost(?WaveConnection $connection, array $body, ?string $tokenOverride = null): array
    {
        $token    = $tokenOverride ?? $this->graphqlToken($connection);
        $response = Http::withToken($token)
            ->acceptJson()
            ->post($this->graphqlEndpoint(), $body);

        $json = $response->json() ?? [];

        if ($response->failed() || ! empty($json['errors'])) {
            logger()->error('Wave GraphQL error', [
                'status'  => $response->status(),
                'errors'  => $json['errors'] ?? [],
                'query'   => $body['query'] ?? '',
                'vars'    => $body['variables'] ?? [],
            ]);

            $message = $json['errors'][0]['message'] ?? ('HTTP ' . $response->status());
            throw new RuntimeException("Wave GraphQL request failed: {$message}");
        }

        return $json;
    }

    public function fetchBusinessId(WaveConnection $connection): string
    {
        $cached = Arr::get($connection->meta ?? [], 'business_id');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $data = $this->graphqlPost($connection, [
            'query' => '{ businesses { edges { node { id name } } } }',
        ], $connection->access_token);

        $edges = Arr::get($data, 'data.businesses.edges', []);

        if (empty($edges)) {
            throw new RuntimeException('No Wave business found for this connection.');
        }

        $graphqlId = (string) Arr::get($edges[0], 'node.id', '');

        if ($graphqlId === '') {
            throw new RuntimeException('Wave API returned a business with no ID.');
        }

        // GraphQL returns base64 global ID e.g. "Business:7c6e10e4-..."
        // Webhook sends the plain UUID — decode and strip the type prefix
        $decoded    = base64_decode($graphqlId);
        $businessId = str_contains($decoded, ':')
            ? substr($decoded, strrpos($decoded, ':') + 1)
            : $graphqlId;

        $connection->forceFill(['meta' => array_merge($connection->meta ?? [], ['business_id' => $businessId])])->save();

        return $businessId;
    }

    /**
     * Fetch all non-archived Wave accounts suitable for payment recording.
     * Returns a normalized array matching the structure used by other PMS API clients.
     */
    public function fetchPaymentAccounts(WaveConnection $connection): array
    {
        $businessId = $this->fetchBusinessId($connection);

        $query = <<<'GQL'
        query GetAccounts($businessId: ID!, $page: Int!, $pageSize: Int!) {
            business(id: $businessId) {
                accounts(page: $page, pageSize: $pageSize) {
                    edges {
                        node {
                            id
                            name
                            type { value }
                            subtype { value }
                            isArchived
                        }
                    }
                }
            }
        }
        GQL;

        $data  = $this->graphqlPost($connection, [
            'query'     => $query,
            'variables' => ['businessId' => $this->toBusinessRelayId($businessId), 'page' => 1, 'pageSize' => 200],
        ], $connection->access_token);

        $edges    = Arr::get($data, 'data.business.accounts.edges', []);
        $accounts = [];


        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];
            if ($node['isArchived'] ?? false) {
                continue;
            }

            $subtype = $node['subtype']['value'] ?? '';

            // Only allow payment accounts
            if (!in_array($subtype, ['CASH_AND_BANK', 'MONEY_IN_TRANSIT'], true)) {
                continue;
            }

            $accounts[] = [
                'account_id'   => (string) ($node['id'] ?? ''),
                'account_name' => (string) ($node['name'] ?? ''),
                'account_type' => (string) ($node['type']['value'] ?? ''),
                'account_subtype' => $subtype,
            ];
        }

        usort($accounts, fn ($a, $b) => strcasecmp($a['account_name'], $b['account_name']));

        return array_values(array_filter($accounts, fn ($a) => $a['account_id'] !== ''));
    }

    /**
     * Fetch all non-archived Wave income accounts, for surcharge-income-account selection.
     * Mirrors fetchPaymentAccounts() but filters to INCOME-type accounts instead of bank/cash.
     */
    public function fetchIncomeAccounts(WaveConnection $connection): array
    {
        $businessId = $this->fetchBusinessId($connection);
        $query = <<<'GQL'
        query GetAccounts($businessId: ID!, $page: Int!, $pageSize: Int!) {
            business(id: $businessId) {
                accounts(page: $page, pageSize: $pageSize) {
                    edges {
                        node {
                            id
                            name
                            type { value }
                            subtype { value }
                            isArchived
                        }
                    }
                }
            }
        }
        GQL;
        $data  = $this->graphqlPost($connection, [
            'query'     => $query,
            'variables' => ['businessId' => $this->toBusinessRelayId($businessId), 'page' => 1, 'pageSize' => 200],
        ], $connection->access_token);
        $edges    = Arr::get($data, 'data.business.accounts.edges', []);
        $accounts = [];
        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];
            if ($node['isArchived'] ?? false) {
                continue;
            }
            if (strtoupper($node['type']['value'] ?? '') !== 'INCOME') {
                continue;
            }
            $accounts[] = [
                'account_id'      => (string) ($node['id'] ?? ''),
                'account_name'    => (string) ($node['name'] ?? ''),
                'account_type'    => (string) ($node['type']['value'] ?? ''),
                'account_subtype' => (string) ($node['subtype']['value'] ?? ''),
            ];
        }
        usort($accounts, fn ($a, $b) => strcasecmp($a['account_name'], $b['account_name']));
        return array_values(array_filter($accounts, fn ($a) => $a['account_id'] !== ''));
    }
    /**
     * Book the surcharge fee as income via moneyTransactionCreate — deposits the fee into
     * the client's deposit account (anchor) and categorizes it against the surcharge income
     * account (line item), without touching the invoice or its Accounts Receivable balance.
     * This is Wave's equivalent of the QuickBooks Debit-bank/Credit-income journal entry.
     */
    public function recordSurchargeIncome(
        WaveConnection $connection,
        string $depositAccountId,
        string $incomeAccountId,
        float $amount,
        string $date,
        string $description,
        ?string $externalId = null,
    ): ?string {
        $businessId = $this->fetchBusinessId($connection);
        $amountStr  = number_format($amount, 2, '.', '');
        $input = [
            'businessId'  => $this->toBusinessRelayId($businessId),
            'date'        => $date,
            'description' => $description,
            'anchor'      => [
                'accountId' => $depositAccountId,
                'amount'    => $amountStr,
                'direction' => 'DEPOSIT',
            ],
            'lineItems'   => [[
                'accountId' => $incomeAccountId,
                'amount'    => $amountStr,
                'balance'   => 'INCREASE',
            ]],
        ];
        if ($externalId !== null && $externalId !== '') {
            $input['externalId'] = $externalId;
        }
        $mutation = <<<'GQL'
        mutation MoneyTransactionCreate($input: MoneyTransactionCreateInput!) {
            moneyTransactionCreate(input: $input) {
                didSucceed
                inputErrors {
                    code
                    message
                    path
                }
                transaction {
                    id
                }
            }
        }
        GQL;
        $data = $this->graphqlPost($connection, [
            'query'     => $mutation,
            'variables' => ['input' => $input],
        ], $connection->access_token);
        $didSucceed  = (bool) Arr::get($data, 'data.moneyTransactionCreate.didSucceed', false);
        $inputErrors = Arr::get($data, 'data.moneyTransactionCreate.inputErrors', []);
        $txnId       = Arr::get($data, 'data.moneyTransactionCreate.transaction.id', '');
        if (! $didSucceed) {
            $errorMsg = collect($inputErrors)->pluck('message')->filter()->implode('; ');
            throw new RuntimeException("Wave surcharge income recording failed: {$errorMsg}");
        }
        return $txnId !== '' ? (string) $txnId : null;
    }
    }

    /**
     * Fetch the Accounts Receivable account ID for the Wave business.
     * Required as anchor.accountId in moneyTransactionCreate for invoice payments.
     */
    public function fetchArAccountId(WaveConnection $connection): string
    {
        $cached = Arr::get($connection->meta ?? [], 'ar_account_id');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $businessId = $this->fetchBusinessId($connection);

        $query = <<<'GQL'
        query GetAccounts($businessId: ID!, $page: Int!, $pageSize: Int!) {
            business(id: $businessId) {
                accounts(page: $page, pageSize: $pageSize) {
                    edges {
                        node {
                            id
                            name
                            type { value }
                            isArchived
                        }
                    }
                }
            }
        }
        GQL;

        $data  = $this->graphqlPost($connection, [
            'query'     => $query,
            'variables' => ['businessId' => $this->toBusinessRelayId($businessId), 'page' => 1, 'pageSize' => 200],
        ], $connection->access_token);

        $edges = Arr::get($data, 'data.business.accounts.edges', []);

        // Primary: name contains "receivable" (Wave auto-creates "Accounts Receivable" for every business)
        $arAccountId = '';
        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];
            if ($node['isArchived'] ?? false) {
                continue;
            }
            if (stripos($node['name'] ?? '', 'receivable') !== false) {
                $arAccountId = (string) ($node['id'] ?? '');
                break;
            }
        }

        // Fallback: first non-archived ASSET account
        if ($arAccountId === '') {
            foreach ($edges as $edge) {
                $node = $edge['node'] ?? [];
                if ($node['isArchived'] ?? false) {
                    continue;
                }
                if (strtoupper($node['type']['value'] ?? '') === 'ASSET') {
                    $arAccountId = (string) ($node['id'] ?? '');
                    break;
                }
            }
        }

        if ($arAccountId === '') {
            throw new RuntimeException('No Accounts Receivable account found in Wave. Ensure the business has an AR account configured.');
        }

        $connection->forceFill(['meta' => array_merge($connection->meta ?? [], ['ar_account_id' => $arAccountId])])->save();

        return $arAccountId;
    }

    /**
     * Resolve the account ID to use for payment recording.
     * Priority: client-configured account → connection meta cache → first ASSET account from API.
     */
    public function fetchDefaultPaymentAccountId(WaveConnection $connection, ?string $clientAccountId = null): string
    {
        if (is_string($clientAccountId) && $clientAccountId !== '') {
            return $clientAccountId;
        }

        $cached = Arr::get($connection->meta ?? [], 'default_payment_account_id');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $businessId = $this->fetchBusinessId($connection);

        $query = <<<'GQL'
        query GetAccounts($businessId: ID!, $page: Int!, $pageSize: Int!) {
            business(id: $businessId) {
                accounts(page: $page, pageSize: $pageSize) {
                    edges {
                        node {
                            id
                            name
                            type { value }
                            isArchived
                        }
                    }
                }
            }
        }
        GQL;

        $data  = $this->graphqlPost($connection, [
            'query'     => $query,
            'variables' => ['businessId' => $this->toBusinessRelayId($businessId), 'page' => 1, 'pageSize' => 50],
        ], $connection->access_token);

        $edges = Arr::get($data, 'data.business.accounts.edges', []);

        $accountId = '';
        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];
            if (! ($node['isArchived'] ?? false) && strtoupper($node['type']['value'] ?? '') === 'ASSET') {
                $accountId = (string) ($node['id'] ?? '');
                break;
            }
        }

        if ($accountId === '') {
            foreach ($edges as $edge) {
                $node = $edge['node'] ?? [];
                if (! ($node['isArchived'] ?? false)) {
                    $accountId = (string) ($node['id'] ?? '');
                    break;
                }
            }
        }

        if ($accountId === '') {
            throw new RuntimeException('No Wave accounts found to record payment against.');
        }

        $connection->forceFill(['meta' => array_merge($connection->meta ?? [], [
            'default_payment_account_id' => $accountId,
        ])])->save();

        return $accountId;
    }

    /**
     * Record an external payment against a Wave invoice via invoicePaymentCreateManual.
     */
    public function recordInvoicePayment(
        WaveConnection $connection,
        string $invoiceRelayId,
        float $amount,
        string $externalRef,
        string $date,
        string $description = 'Payment recorded from Payment Middleware checkout',
        ?string $clientAccountId = null,
        string $paymentMethod = 'OTHER',
    ): void {
        // invoicePaymentCreateManual expects the full compound Relay ID as stored from the listing query.
        // Do NOT strip the Business: prefix — Wave uses the full "Business:uuid;Invoice:id" as the node ID.
        $rawInvoiceRelayId = $invoiceRelayId;
        $decoded = base64_decode($invoiceRelayId);

        $paymentAccountId = $this->fetchDefaultPaymentAccountId($connection, $clientAccountId);

        $input = [
            'invoiceId'        => $invoiceRelayId,
            'paymentAccountId' => $paymentAccountId,
            'amount'           => number_format($amount, 2, '.', ''),
            'paymentDate'      => $date,
            'paymentMethod'    => $paymentMethod,
            'exchangeRate'     => '1.00',
            'memo'             => $description,
        ];

        $mutation = <<<'GQL'
        mutation RecordPayment($input: InvoicePaymentCreateManualInput!) {
            invoicePaymentCreateManual(input: $input) {
                didSucceed
                inputErrors {
                    code
                    message
                    path
                }
                invoicePayment {
                    id
                }
            }
        }
        GQL;

        logger()->info('Wave: recordInvoicePayment — request', [
            'mutation'              => 'invoicePaymentCreateManual',
            'raw_invoice_relay_id'  => $rawInvoiceRelayId,
            'decoded_relay_id'      => $decoded,
            'normalized_invoice_id' => $invoiceRelayId,
            'payment_account_id'    => $paymentAccountId,
            'input'                 => $input,
            'pms_client_id'         => $connection->pms_client_id,
        ]);

        try {
            $data = $this->graphqlPost($connection, [
                'query'     => $mutation,
                'variables' => ['input' => $input],
            ], $connection->access_token);
        } catch (\Throwable $e) {
            logger()->error('Wave: recordInvoicePayment — graphqlPost threw', [
                'exception'  => $e->getMessage(),
                'input'      => $input,
                'mutation'   => trim($mutation),
            ]);
            throw $e;
        }

        logger()->info('Wave: recordInvoicePayment — raw response', [
            'response' => $data,
        ]);

        $didSucceed  = (bool) Arr::get($data, 'data.invoicePaymentCreateManual.didSucceed', false);
        $inputErrors = Arr::get($data, 'data.invoicePaymentCreateManual.inputErrors', []);
        $paymentId   = Arr::get($data, 'data.invoicePaymentCreateManual.invoicePayment.id', '');

        logger()->info('Wave: recordInvoicePayment — result', [
            'did_succeed'    => $didSucceed,
            'payment_id'     => $paymentId,
            'input_errors'   => $inputErrors,
            'full_data_path' => $data['data'] ?? null,
        ]);

        if (! $didSucceed) {
            $errorMsg = collect($inputErrors)->pluck('message')->filter()->implode('; ');
            throw new RuntimeException("Wave payment recording failed: {$errorMsg}");
        }
    }

    /**
     * List invoices modified since a given time, for change-detection polling —
     * Wave has no reliable "invoice updated" webhook (see PollWaveInvoicesJob).
     *
     * Wave's public docs describe a server-side `modifiedAtStart` filter on this
     * connection, but its exact argument shape is unverified against a live
     * connection. Rather than bet the whole query on a guessed filter/sort
     * argument, this only relies on the `modifiedAt` scalar field (confirmed in
     * Wave's schema docs) and filters client-side — same brute-force pagination
     * already proven working in findInvoiceByWebhookId().
     */
    public function fetchInvoicesModifiedSince(WaveConnection $connection, \DateTimeInterface $since): array
    {
        $businessId = $this->fetchBusinessId($connection);
        $query = <<<'GQL'
        query ListInvoices($businessId: ID!, $page: Int!, $pageSize: Int!) {
            business(id: $businessId) {
                invoices(page: $page, pageSize: $pageSize) {
                    edges {
                        node {
                            id
                            invoiceNumber
                            status
                            modifiedAt
                        }
                    }
                }
            }
        }
        GQL;
        $page       = 1;
        $pageSize   = 50;
        $maxPages   = 10;
        $changed    = [];
        $reachedEnd = false;
        while ($page <= $maxPages) {
            $data  = $this->graphqlPost($connection, [
                'query'     => $query,
                'variables' => ['businessId' => $this->toBusinessRelayId($businessId), 'page' => $page, 'pageSize' => $pageSize],
            ], $connection->access_token);
            $edges = Arr::get($data, 'data.business.invoices.edges', []);
            if (empty($edges)) {
                break;
            }
            foreach ($edges as $edge) {
                $node       = $edge['node'] ?? [];
                $modifiedAt = $node['modifiedAt'] ?? null;
                if ($modifiedAt === null) {
                    continue;
                }
                try {
                    $modifiedAtDate = new \DateTimeImmutable($modifiedAt);
                } catch (\Throwable) {
                    continue;
                }
                if ($modifiedAtDate <= $since) {
                    continue;
                }
                $decoded   = base64_decode($node['id'] ?? '');
                $numericId = str_contains($decoded, ':')
                    ? substr($decoded, strrpos($decoded, ':') + 1)
                    : ($node['id'] ?? '');
                if ($numericId === '') {
                    continue;
                }
                $changed[] = [
                    'id'             => $numericId,
                    'invoice_number' => (string) ($node['invoiceNumber'] ?? ''),
                    'status'         => (string) ($node['status'] ?? ''),
                    'modified_at'    => $modifiedAt,
                ];
            }
            if (count($edges) < $pageSize) {
                $reachedEnd = true;
                break;
            }
            $page++;
        }
        if (! $reachedEnd && $page > $maxPages) {
            logger()->warning('Wave: fetchInvoicesModifiedSince hit the page cap — older invoices were not scanned this run', [
                'pms_client_id' => $connection->pms_client_id,
                'max_pages'     => $maxPages,
                'page_size'     => $pageSize,
            ]);
        }
        return $changed;
    }
    
    /**
     * Fetch a Wave invoice by its webhook integer ID.
     * Wave's invoices() connection returns Relay global IDs; we decode each to match the plain integer.
     * Returns an empty array if the invoice is not found within the first page.
     */
    public function findInvoiceByWebhookId(WaveConnection $connection, string $webhookInvoiceId): array
    {
        $businessId = $this->fetchBusinessId($connection);

        $query = <<<'GQL'
        query ListInvoices($businessId: ID!, $page: Int!, $pageSize: Int!) {
            business(id: $businessId) {
                invoices(page: $page, pageSize: $pageSize) {
                    edges {
                        node {
                            id
                            invoiceNumber
                            status
                            amountDue { value }
                            customer { id name email }
                            dueDate
                        }
                    }
                }
            }
        }
        GQL;

        $page     = 1;
        $pageSize = 50;

        while ($page <= 10) {
            $data  = $this->graphqlPost($connection, [
                'query'     => $query,
                'variables' => ['businessId' => $this->toBusinessRelayId($businessId), 'page' => $page, 'pageSize' => $pageSize],
            ], $connection->access_token);

            $edges = Arr::get($data, 'data.business.invoices.edges', []);

            if (empty($edges)) {
                break;
            }

            foreach ($edges as $edge) {
                $node    = $edge['node'] ?? [];
                $decoded = base64_decode($node['id'] ?? '');
                // Relay global ID decodes to "Invoice:2530533149167878162"
                $numericId = str_contains($decoded, ':')
                    ? substr($decoded, strrpos($decoded, ':') + 1)
                    : ($node['id'] ?? '');

                if ($numericId === $webhookInvoiceId) {
                    return $node;
                }
            }

            if (count($edges) < $pageSize) {
                break; // last page
            }

            $page++;
        }

        return [];
    }

    /**
     * Revoke this connection's OAuth token via Wave's token-revoke endpoint, which stops
     * all further API access and webhook deliveries for the business. Wave's GraphQL API
     * has no per-app "uninstall" mutation — token revocation is the real disconnect mechanism.
     */
    public function uninstallApp(WaveConnection $connection): bool
    {
        $token = $connection->refresh_token ?: $connection->access_token;

        if (! $token) {
            return true;
        }

        $response = Http::asForm()->post(
            rtrim(config('services.wave.base_url', 'https://api.waveapps.com'), '/') . '/oauth2/token-revoke/',
            [
                'client_id'       => config('services.wave.client_id'),
                'client_secret'   => config('services.wave.client_secret'),
                'token'           => $token,
                'token_type_hint' => $connection->refresh_token ? 'refresh_token' : 'access_token',
            ]
        );

        if ($response->failed()) {
            logger()->warning('Wave: token revocation failed', [
                'pms_client_id' => $connection->pms_client_id,
                'status'        => $response->status(),
                'body'          => $response->json() ?? $response->body(),
            ]);

            return false;
        }

        return true;
    }

    public function fetchInvoice(WaveConnection $connection, string $invoiceId): array
    {
        // business(id:) takes the plain UUID; invoice(id:) requires a Relay global ID: base64("Invoice:{id}")
        $businessId   = $this->fetchBusinessId($connection);
        $graphqlInvoiceId = base64_encode('Invoice:' . $invoiceId);

        // Wave's Business type has invoice(id:) singular — fields confirmed against Wave's schema.
        // amountDue only returns `value`, not nested currency; currency comes from the webhook payload.
        $query = <<<'GQL'
        query GetInvoice($businessId: ID!, $invoiceId: ID!) {
            business(id: $businessId) {
                invoice(id: $invoiceId) {
                    id
                    invoiceNumber
                    status
                    invoiceDate
                    dueDate
                    amountDue {
                        value
                    }
                    customer {
                        id
                        name
                        email
                    }
                }
            }
        }
        GQL;

        $data = $this->graphqlPost($connection, [
            'query'     => $query,
            'variables' => ['businessId' => $this->toBusinessRelayId($businessId), 'invoiceId' => $graphqlInvoiceId],
        ], $connection->access_token);

        $invoice = Arr::get($data, 'data.business.invoice');

        if (! $invoice) {
            throw new RuntimeException("Wave invoice [{$invoiceId}] not found.");
        }

        return $invoice;
    }
}
