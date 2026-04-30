<?php

namespace Modules\Inbound\Services;

use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\PmsConnection;

class ZohoRegionResolver
{
    public const REGIONS = [
        'US' => 'https://accounts.zoho.com',
        'AU' => 'https://accounts.zoho.com.au',
        'EU' => 'https://accounts.zoho.eu',
        'IN' => 'https://accounts.zoho.in',
        'CN' => 'https://accounts.zoho.com.cn',
        'JP' => 'https://accounts.zoho.jp',
        'SA' => 'https://accounts.zoho.sa',
        'CA' => 'https://accounts.zohocloud.ca',
    ];

    public const BOOKS_API_BASE_URLS = [
        'US' => 'https://www.zohoapis.com/books/v3',
        'AU' => 'https://www.zohoapis.com.au/books/v3',
        'EU' => 'https://www.zohoapis.eu/books/v3',
        'IN' => 'https://www.zohoapis.in/books/v3',
        'CN' => 'https://www.zohoapis.com.cn/books/v3',
        'JP' => 'https://www.zohoapis.jp/books/v3',
        'SA' => 'https://www.zohoapis.sa/books/v3',
        'CA' => 'https://www.zohoapis.ca/books/v3',
    ];

    public const INVOICE_API_BASE_URLS = [
        'US' => 'https://www.zohoapis.com/invoice/v3',
        'AU' => 'https://www.zohoapis.com.au/invoice/v3',
        'EU' => 'https://www.zohoapis.eu/invoice/v3',
        'IN' => 'https://www.zohoapis.in/invoice/v3',
        'CN' => 'https://www.zohoapis.com.cn/invoice/v3',
        'JP' => 'https://www.zohoapis.jp/invoice/v3',
        'SA' => 'https://www.zohoapis.sa/invoice/v3',
        'CA' => 'https://www.zohoapis.ca/invoice/v3',
    ];

    public function options(): array
    {
        return [
            'US' => 'United States',
            'AU' => 'Australia',
            'EU' => 'Europe',
            'IN' => 'India',
            'CN' => 'China',
            'JP' => 'Japan',
            'SA' => 'Saudi Arabia',
            'CA' => 'Canada',
        ];
    }

    public function accountsBaseUrlForClientId(string $pmsClientId): string
    {
        $client = Client::query()
            ->where('pms_client_id', $pmsClientId)
            ->first();

        return $this->accountsBaseUrlForClient($client);
    }

    public function accountsBaseUrlForConnection(PmsConnection $connection): string
    {
        $client = Client::query()
            ->where('pms_client_id', $connection->pms_client_id)
            ->first();

        return $this->accountsBaseUrlForClient($client);
    }

    public function booksApiBaseUrlForConnection(PmsConnection $connection): string
    {
        $client = Client::query()
            ->where('pms_client_id', $connection->pms_client_id)
            ->first();

        $region = $this->normalize($client?->zoho_region);

        return self::BOOKS_API_BASE_URLS[$region];
    }

    public function invoiceApiBaseUrlForConnection(PmsConnection $connection): string
    {
        $client = Client::query()
            ->where('pms_client_id', $connection->pms_client_id)
            ->first();

        $region = $this->normalize($client?->zoho_region);

        return self::INVOICE_API_BASE_URLS[$region];
    }

    public function normalize(?string $region): string
    {
        $region = strtoupper(trim((string) $region));

        return array_key_exists($region, self::REGIONS) ? $region : 'US';
    }

    private function accountsBaseUrlForClient(?Client $client): string
    {
        $region = $this->normalize($client?->zoho_region);

        return self::REGIONS[$region];
    }
}
