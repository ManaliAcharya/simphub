<?php

namespace Modules\BookSync\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\BookSync\Models\BookSyncMerchant;
use RuntimeException;

class BookSyncQBClient
{
    private const PAYMENT_METHODS = ['Cash', 'Credit Card', 'Debit Card', 'Check', 'Other'];

    public function __construct(
        private readonly BookSyncQBOAuthService $oauth,
    ) {}

    // ── Deposit accounts ─────────────────────────────────────────────────────

    public function fetchDepositAccounts(BookSyncMerchant $merchant): array
    {
        $merchant = $this->oauth->ensureValidToken($merchant);

        $sql = "SELECT Id, Name, AccountType, AccountSubType FROM Account "
             . "WHERE AccountType IN ('Bank', 'Other Current Asset') AND Active = true "
             . "ORDER BY Name";

        $response = $this->request($merchant)
            ->get('/query', ['query' => $sql, ...$this->mv()])
            ->throw()
            ->json();

        $rows = $response['QueryResponse']['Account'] ?? [];

        return collect(is_array($rows) ? $rows : [])
            ->map(fn (array $a) => [
                'id'   => (string) ($a['Id'] ?? ''),
                'name' => (string) ($a['Name'] ?? ''),
            ])
            ->filter(fn (array $a) => $a['id'] !== '')
            ->values()
            ->all();
    }

    // ── Customer management ───────────────────────────────────────────────────

    /**
     * Look up a QB customer by DisplayName. Returns the QB customer ID or null if not found.
     * Used when a transaction provides an explicit customer_name.
     */
    public function findCustomerByDisplayName(BookSyncMerchant $merchant, string $displayName): ?string
    {
        $merchant = $this->oauth->ensureValidToken($merchant);
        $id       = $this->findCustomerByName($merchant, $displayName);

        return $id !== '' ? $id : null;
    }

    public function findOrCreateCustomer(BookSyncMerchant $merchant, string $displayName, ?string $email = null): string
    {
        $merchant = $this->oauth->ensureValidToken($merchant);

        $existing = $this->findCustomerByName($merchant, $displayName);
        if ($existing !== '') {
            return $existing;
        }

        return $this->createCustomer($merchant, $displayName, $email);
    }

    private function findCustomerByName(BookSyncMerchant $merchant, string $displayName): string
    {
        $safe = str_replace("'", "\\'", $displayName);
        $sql  = "SELECT Id, Active FROM Customer WHERE DisplayName = '{$safe}'";

        $response = $this->request($merchant)
            ->get('/query', ['query' => $sql, ...$this->mv()])
            ->throw()
            ->json();

        $rows = $response['QueryResponse']['Customer'] ?? [];

        if (! is_array($rows) || empty($rows)) {
            return '';
        }

        $active = array_values(array_filter($rows, fn ($r) => ($r['Active'] ?? true) === true));

        if (count($active) > 1) {
            Log::warning('BookSync: multiple active QB customers matched DisplayName; using first.', [
                'merchant_id'  => $merchant->merchant_id,
                'display_name' => $displayName,
                'count'        => count($active),
            ]);
        }

        return (string) (($active[0] ?? $rows[0])['Id'] ?? '');
    }

    private function createCustomer(BookSyncMerchant $merchant, string $displayName, ?string $email): string
    {
        $payload = ['DisplayName' => $displayName];

        if ($email) {
            $payload['PrimaryEmailAddr'] = ['Address' => $email];
        }

        $response = $this->request($merchant)
            ->withQueryParameters($this->mv())
            ->post('/customer', $payload)
            ->throw()
            ->json();

        $id = (string) data_get($response, 'Customer.Id', '');

        if ($id === '') {
            throw new RuntimeException("Failed to create QuickBooks customer for [{$displayName}].");
        }

        return $id;
    }

    // ── Items (income / service) ──────────────────────────────────────────────

    public function fetchItems(BookSyncMerchant $merchant): array
    {
        $merchant = $this->oauth->ensureValidToken($merchant);

        $sql = "SELECT Id, Name, Type FROM Item "
             . "WHERE Type IN ('Service', 'NonInventory') AND Active = true "
             . "ORDER BY Name MAXRESULTS 100";

        $response = $this->request($merchant)
            ->get('/query', ['query' => $sql, ...$this->mv()])
            ->throw()
            ->json();

        $rows = $response['QueryResponse']['Item'] ?? [];

        return collect(is_array($rows) ? $rows : [])
            ->map(fn (array $i) => [
                'id'   => (string) ($i['Id'] ?? ''),
                'name' => (string) ($i['Name'] ?? ''),
                'type' => (string) ($i['Type'] ?? ''),
            ])
            ->filter(fn (array $i) => $i['id'] !== '')
            ->values()
            ->all();
    }

    // ── Customers ─────────────────────────────────────────────────────────────

    public function fetchCustomers(BookSyncMerchant $merchant): array
    {
        $merchant = $this->oauth->ensureValidToken($merchant);

        $sql = "SELECT Id, DisplayName FROM Customer "
             . "WHERE Active = true ORDER BY DisplayName MAXRESULTS 200";

        $response = $this->request($merchant)
            ->get('/query', ['query' => $sql, ...$this->mv()])
            ->throw()
            ->json();

        $rows = $response['QueryResponse']['Customer'] ?? [];

        return collect(is_array($rows) ? $rows : [])
            ->map(fn (array $c) => [
                'id'   => (string) ($c['Id'] ?? ''),
                'name' => (string) ($c['DisplayName'] ?? ''),
            ])
            ->filter(fn (array $c) => $c['id'] !== '')
            ->values()
            ->all();
    }

    // ── Payment method ────────────────────────────────────────────────────────

    /**
     * Find an existing QBO PaymentMethod by name. Returns null if not found.
     * BookSync never creates payment methods — they must exist in QBO.
     */
    public function findPaymentMethod(BookSyncMerchant $merchant, string $name): ?string
    {
        $merchant = $this->oauth->ensureValidToken($merchant);

        $safe = str_replace("'", "\\'", $name);
        $sql  = "SELECT Id FROM PaymentMethod WHERE Name = '{$safe}' AND Active = true MAXRESULTS 1";

        $response = $this->request($merchant)
            ->get('/query', ['query' => $sql, ...$this->mv()])
            ->throw()
            ->json();

        $rows = $response['QueryResponse']['PaymentMethod'] ?? [];

        if (is_array($rows) && ! empty($rows)) {
            return (string) ($rows[0]['Id'] ?? '');
        }

        return null;
    }

    // ── Default service item ──────────────────────────────────────────────────

    /**
     * Finds an existing "Services" or "Sales" item in the merchant's QB company.
     * If neither exists, creates a new "Services" service item.
     * The merchant can override this on their setup page if needed.
     */
    public function findOrCreateServiceItem(BookSyncMerchant $merchant): array
    {
        $merchant = $this->oauth->ensureValidToken($merchant);

        $sql = "SELECT Id, Name FROM Item WHERE Name IN ('Services', 'Sales') AND Active = true MAXRESULTS 2";

        $response = $this->request($merchant)
            ->get('/query', ['query' => $sql, ...$this->mv()])
            ->throw()
            ->json();

        $rows = $response['QueryResponse']['Item'] ?? [];

        if (is_array($rows) && ! empty($rows)) {
            // Prefer "Services" over "Sales"
            $services = collect($rows)->first(fn ($r) => ($r['Name'] ?? '') === 'Services');
            $item     = $services ?? $rows[0];

            return ['id' => (string) ($item['Id'] ?? ''), 'name' => (string) ($item['Name'] ?? 'Services')];
        }

        $incomeAccountId = $this->findIncomeAccountId($merchant);

        $created = $this->request($merchant)
            ->withQueryParameters($this->mv())
            ->post('/item', [
                'Name'             => 'Services',
                'Type'             => 'Service',
                'IncomeAccountRef' => ['value' => $incomeAccountId],
            ])
            ->throw()
            ->json();

        $id = (string) data_get($created, 'Item.Id', '');

        if ($id === '') {
            throw new RuntimeException('Failed to create Services item in QuickBooks.');
        }

        return ['id' => $id, 'name' => 'Services'];
    }

    private function findIncomeAccountId(BookSyncMerchant $merchant): string
    {
        $sql = "SELECT Id FROM Account WHERE AccountType = 'Income' AND Active = true ORDER BY Id MAXRESULTS 1";

        $response = $this->request($merchant)
            ->get('/query', ['query' => $sql, ...$this->mv()])
            ->throw()
            ->json();

        $rows = $response['QueryResponse']['Account'] ?? [];

        if (empty($rows)) {
            throw new RuntimeException('No Income account found in QuickBooks to attach the BookSync Sale item.');
        }

        return (string) ($rows[0]['Id'] ?? '');
    }

    // ── Sales Receipt ─────────────────────────────────────────────────────────

    public function createSalesReceipt(
        BookSyncMerchant $merchant,
        string $customerId,
        string $paymentMethodId,
        array $serviceItem,
        float $amount,
        string $txnDate,
        string $docNumber,
        ?string $customerName = null,
        ?string $privateNote = null,
        ?array $surchargeItem = null,
        float $surchargeAmount = 0.0,
    ): array {
        $merchant = $this->oauth->ensureValidToken($merchant);

        $description = $customerName
            ? "{$customerName} — {$docNumber}"
            : "POS Sale — {$docNumber}";

        $lines = [
            [
                'Amount'              => $amount,
                'DetailType'          => 'SalesItemLineDetail',
                'Description'         => $description,
                'SalesItemLineDetail' => [
                    'ItemRef'   => ['value' => $serviceItem['id'], 'name' => $serviceItem['name']],
                    'Qty'       => 1,
                    'UnitPrice' => $amount,
                ],
            ],
        ];

        if ($surchargeItem && $surchargeAmount > 0) {
            $lines[] = [
                'Amount'              => $surchargeAmount,
                'DetailType'          => 'SalesItemLineDetail',
                'Description'         => "Surcharge — {$docNumber}",
                'SalesItemLineDetail' => [
                    'ItemRef'   => ['value' => $surchargeItem['id'], 'name' => $surchargeItem['name']],
                    'Qty'       => 1,
                    'UnitPrice' => $surchargeAmount,
                ],
            ];
        }

        $payload = [
            'CustomerRef'         => ['value' => $customerId],
            'DepositToAccountRef' => ['value' => $merchant->deposit_account_id],
            'PaymentMethodRef'    => ['value' => $paymentMethodId],
            'TxnDate'             => $txnDate,
            'DocNumber'           => substr($docNumber, 0, 21),
            'Line'                => $lines,
        ];

        if ($privateNote) {
            $payload['PrivateNote'] = substr($privateNote, 0, 4000);
        }

        $response = $this->request($merchant)
            ->withQueryParameters($this->mv())
            ->post('/salesreceipt', $payload)
            ->throw()
            ->json();

        $id = (string) data_get($response, 'SalesReceipt.Id', '');

        if ($id === '') {
            throw new RuntimeException('QuickBooks did not return a Sales Receipt ID.');
        }

        return [
            'qb_salesreceipt_id' => $id,
            'qb_customer_id'     => $customerId,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function request(BookSyncMerchant $merchant): PendingRequest
    {
        if (! $merchant->qb_realm_id) {
            throw new RuntimeException('Merchant has no QuickBooks realm ID.');
        }

        $baseUrl = rtrim(config('services.quickbooks.base_url', 'https://quickbooks.api.intuit.com'), '/')
                 . "/v3/company/{$merchant->qb_realm_id}";

        return Http::withToken($merchant->qb_access_token)
            ->acceptJson()
            ->asJson()
            ->baseUrl($baseUrl);
    }

    private function mv(): array
    {
        return ['minorversion' => config('services.quickbooks.minor_version', '65')];
    }

    public static function normalizePaymentMethod(string $method): string
    {
        return in_array($method, self::PAYMENT_METHODS, true) ? $method : 'Other';
    }
}
