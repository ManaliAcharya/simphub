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
