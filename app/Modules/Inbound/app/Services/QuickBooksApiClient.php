<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\QuickBooksConnection;

class QuickBooksApiClient
{
    public function __construct(
        private readonly QuickBooksOAuthService $oauth,
    ) {}

    private function request(QuickBooksConnection &$connection): PendingRequest
    {
        $realmId = $connection->realmId();

        if ($realmId === '') {
            throw new \RuntimeException('QuickBooks realmId is not set on this connection.');
        }

        return Http::withToken($connection->access_token)
            ->acceptJson()
            ->asJson()
            ->baseUrl($this->baseUrl($connection)."/v3/company/{$realmId}")
            ->retry(2, 0, function (\Throwable $e, PendingRequest $req) use (&$connection): bool {
                if (! $e instanceof \Illuminate\Http\Client\RequestException) {
                    return false;
                }
                if ($e->response->status() !== 401) {
                    return false;
                }
                try {
                    $connection = $this->oauth->refreshAccessToken($connection);
                    $req->withToken($connection->access_token);
                    return true;
                } catch (\Throwable) {
                    return false;
                }
            }, throw: true);
    }

    private function minorVersion(): array
    {
        return ['minorversion' => config('services.quickbooks.minor_version', '65')];
    }

    private function baseUrl(QuickBooksConnection $connection): string
    {
        return $connection->environment() === 'production'
            ? rtrim((string) config('services.quickbooks.base_url_production'), '/')
            : rtrim((string) config('services.quickbooks.base_url'), '/');
    }

    public function fetchInvoice(QuickBooksConnection $connection, string $invoiceId): array
    {
        return $this->request($connection)
            ->get("/invoice/{$invoiceId}", $this->minorVersion())
            ->throw()
            ->json();
    }

    public function fetchCustomer(QuickBooksConnection $connection, string $customerId): array
    {
        return $this->request($connection)
            ->get("/customer/{$customerId}", $this->minorVersion())
            ->throw()
            ->json();
    }

    public function recordPayment(QuickBooksConnection $connection, array $payload): array
    {
        return $this->request($connection)
            ->withQueryParameters($this->minorVersion())
            ->post('/payment', $payload)
            ->throw()
            ->json();
    }

    public function recordJournalEntry(QuickBooksConnection $connection, array $payload): array
    {
        return $this->request($connection)
            ->withQueryParameters($this->minorVersion())
            ->post('/journalentry', $payload)
            ->throw()
            ->json();
    }

    public function fetchArAccountId(QuickBooksConnection $connection): string
    {
        $sql = "SELECT Id FROM Account WHERE AccountType = 'Accounts Receivable' AND Active = true ORDERBY Id LIMIT 1";

        $response = $this->request($connection)
            ->get('/query', array_merge(['query' => $sql], $this->minorVersion()))
            ->throw()
            ->json();

        $rows = $response['QueryResponse']['Account'] ?? [];

        if (empty($rows)) {
            throw new \RuntimeException('No Accounts Receivable account found in QuickBooks.');
        }

        return (string) ($rows[0]['Id'] ?? '');
    }

    public function fetchIncomeAccounts(QuickBooksConnection $connection): array
    {
        $sql = "SELECT Id, Name, AccountType, AccountSubType FROM Account "
             . "WHERE AccountType IN ('Income', 'Other Income') AND Active = true "
             . "ORDERBY Name";

        $response = $this->request($connection)
            ->get('/query', array_merge(['query' => $sql], $this->minorVersion()))
            ->throw()
            ->json();

        $rows = $response['QueryResponse']['Account'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->map(fn (array $account) => [
                'account_id'   => (string) ($account['Id']          ?? ''),
                'account_name' => (string) ($account['Name']        ?? 'Unknown'),
                'account_type' => (string) ($account['AccountType'] ?? ''),
                'account_sub'  => (string) ($account['AccountSubType'] ?? ''),
            ])
            ->filter(fn (array $a) => $a['account_id'] !== '')
            ->values()
            ->all();
    }

    public function fetchChartOfAccounts(QuickBooksConnection $connection): array
    {
        $sql = "SELECT Id, Name, AccountType, AccountSubType FROM Account "
             . "WHERE AccountType IN ('Bank', 'Other Current Asset') AND Active = true "
             . "ORDERBY Name";

        $response = $this->request($connection)
            ->get('/query', array_merge(['query' => $sql], $this->minorVersion()))
            ->throw()
            ->json();

        $rows = $response['QueryResponse']['Account'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->map(fn (array $account) => [
                'account_id'   => (string) ($account['Id']          ?? ''),
                'account_name' => (string) ($account['Name']        ?? 'Unknown'),
                'account_type' => (string) ($account['AccountType'] ?? ''),
                'account_sub'  => (string) ($account['AccountSubType'] ?? ''),
            ])
            ->filter(fn (array $a) => $a['account_id'] !== '')
            ->values()
            ->all();
    }

    public function queryInvoice(QuickBooksConnection $connection, string $invoiceId): array
    {
        $sql = "SELECT * FROM Invoice WHERE Id = '{$invoiceId}'";

        return $this->request($connection)
            ->get('/query', array_merge(['query' => $sql], $this->minorVersion()))
            ->throw()
            ->json();
    }

    public function readPayment(QuickBooksConnection $connection, string $paymentId): array
    {
        return $this->request($connection)
            ->get("/payment/{$paymentId}", $this->minorVersion())
            ->throw()
            ->json();
    }

    public function fetchInvoicePdf(QuickBooksConnection $connection, string $invoiceId): ?string
    {
        $cacheKey = "qb_invoice_pdf_{$connection->id}_{$invoiceId}";

        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            return $cached ? base64_decode($cached) : null;
        }

        $realmId = $connection->realmId();

        if ($realmId === '') {
            return null;
        }

        $baseUrl = $this->baseUrl($connection)."/v3/company/{$realmId}";

        try {
            $response = Http::withToken($connection->access_token)
                ->withHeaders(['Accept' => 'application/pdf'])
                ->baseUrl($baseUrl)
                ->get("/invoice/{$invoiceId}/pdf", $this->minorVersion())
                ->throw();

            $pdf = $response->body();

            if ($pdf === '') {
                return null;
            }

            Cache::put($cacheKey, base64_encode($pdf), now()->addMinutes(10));

            return $pdf;
        } catch (\Throwable) {
            return null;
        }
    }

    public function clearPdfCache(QuickBooksConnection $connection, string $invoiceId): void
    {
        Cache::forget("qb_invoice_pdf_{$connection->id}_{$invoiceId}");
    }
}
