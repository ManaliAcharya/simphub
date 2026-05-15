<?php

namespace Modules\Outbound\Adapters;

use Illuminate\Support\Facades\Http;
use Modules\Outbound\Contracts\TerminalAdapterInterface;

class ValorAdapter implements TerminalAdapterInterface
{
    public function code(): string
    {
        return 'valor';
    }

    public function process(string $merchantId, array $payload, array $credentials = []): array
    {
        $authToken = (string) ($credentials['auth_token'] ?? env('VALOR_AUTH_TOKEN', ''));
        $appId     = (string) ($credentials['app_id']    ?? env('VALOR_APP_ID', ''));
        $appKey    = (string) ($credentials['api_key']   ?? env('VALOR_API_KEY', ''));
        $baseUrl   = rtrim((string) ($credentials['base_url'] ?? env('VALOR_BASE_URL', 'https://valorpaytech.com')), '/');
        $endpoint  = (string) ($credentials['param_fetch_path'] ?? env('VALOR_PARAM_FETCH_PATH', '/api'));

        $epi = (string) ($credentials['epi'] ?? $payload['epi'] ?? '');

        $response = Http::withHeaders([
                'Authorization' => "Bearer {$authToken}",
                'appid'         => $appId,
                'appkey'        => $appKey,
                'Content-Type'  => 'application/json',
            ])
            ->timeout(60)
            ->post("{$baseUrl}{$endpoint}", ['epi' => $epi])
            ->throw()
            ->json();

        return $response ?? [];
    }
}
