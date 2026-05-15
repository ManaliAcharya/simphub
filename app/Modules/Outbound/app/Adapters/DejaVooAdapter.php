<?php

namespace Modules\Outbound\Adapters;

use Illuminate\Support\Facades\Http;
use Modules\Outbound\Contracts\TerminalAdapterInterface;

class DejaVooAdapter implements TerminalAdapterInterface
{
    public function code(): string
    {
        return 'dejavoo';
    }

    public function process(string $merchantId, array $payload, array $credentials = []): array
    {
        $apiKey  = (string) ($credentials['api_key']  ?? env('DEJAVOO_API_KEY', ''));
        $baseUrl = rtrim((string) ($credentials['base_url'] ?? env('DEJAVOO_BASE_URL', 'https://api.dejavoo.com')), '/');

        // TODO: update endpoint path and request shape once DejaVoo API specs are confirmed
        $response = Http::withHeaders(['Authorization' => "Bearer {$apiKey}"])
            ->timeout(60)
            ->post("{$baseUrl}/transaction", array_merge(['merchant_id' => $merchantId], $payload))
            ->throw()
            ->json();

        return $response ?? [];
    }
}
