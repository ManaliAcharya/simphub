<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\QuickBooksConnection;
use RuntimeException;

class QuickBooksOAuthService
{
    public function __construct(
        private readonly PmsOAuthStateService $state,
    ) {}

    public function authorizationUrl(string $pmsClientId): string
    {
        $environment = $this->environmentFor($pmsClientId);

        return config('services.quickbooks.oauth_base_url').'/connect/oauth2?'.http_build_query([
            'client_id'     => $this->clientId($environment),
            'response_type' => 'code',
            'scope'         => config('services.quickbooks.scope', 'com.intuit.quickbooks.accounting'),
            'redirect_uri'  => $this->redirectUri(),
            'state'         => $this->state->make('quickbooks', $pmsClientId),
        ]);
    }

    public function exchangeCode(string $code, string $realmId, string $pmsClientId): QuickBooksConnection
    {
        if ($realmId === '') {
            throw new RuntimeException('QuickBooks realmId is missing from the OAuth callback.');
        }

        return $this->createConnection($this->exchangeCodeTokens($code, $pmsClientId), $realmId, $pmsClientId);
    }

    public function exchangeCodeTokens(string $code, string $pmsClientId): array
    {
        $environment = $this->environmentFor($pmsClientId);

        return Http::asForm()
            ->acceptJson()
            ->withBasicAuth($this->clientId($environment), $this->clientSecret($environment))
            ->post(config('services.quickbooks.token_url').'/oauth2/v1/tokens/bearer', [
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => $this->redirectUri(),
            ])
            ->throw()
            ->json();
    }

    public function fetchCompanies(string $accessToken): array
    {
        $response = Http::acceptJson()
            ->withToken($accessToken)
            ->get(config('services.quickbooks.oauth_base_url').'/api/v1/OpenID/GetCompanies');

        if ($response->failed()) {
            return [];
        }

        $list = data_get($response->json(), 'CompanyList.Company', []);

        // Intuit returns a single company as an object, multiple as an array
        if (isset($list['CompanyName'])) {
            $list = [$list];
        }

        return collect($list)
            ->filter(fn ($c) => is_array($c) && isset($c['CompanyName']))
            ->map(function ($c) {
                // RealmID may be a string or a JsonObject wrapper like {'$': '123'}
                $realmId = $c['RealmID'] ?? $c['CompanyID'] ?? '';
                if (is_array($realmId)) {
                    $realmId = $realmId['$'] ?? '';
                }

                return [
                    'realmId'     => (string) $realmId,
                    'companyName' => (string) $c['CompanyName'],
                    'isActive'    => ($c['IsActive'] ?? 'true') === 'true',
                ];
            })
            ->filter(fn ($c) => $c['realmId'] !== '')
            ->values()
            ->all();
    }

    public function createConnection(array $tokenData, string $realmId, string $pmsClientId, ?string $companyName = null): QuickBooksConnection
    {
        if ($realmId === '') {
            throw new RuntimeException('QuickBooks realmId is required to save a connection.');
        }

        // Check if this QB company is already connected to a DIFFERENT client.
        // Reconnecting the same company to the same client is always allowed.
        $existing = QuickBooksConnection::query()
            ->where('provider', 'quickbooks')
            ->get()
            ->first(fn (QuickBooksConnection $c) => $c->realmId() === $realmId);

        if ($existing && (string) $existing->pms_client_id !== (string) $pmsClientId) {
            throw new RuntimeException(
                'This QuickBooks company is already connected to another client. '
                . 'Each QuickBooks company can only be linked to one client. '
                . 'Please disconnect it from the other client first, or select a different QuickBooks company.'
            );
        }

        return $this->persistTokens($tokenData, realmId: $realmId, pmsClientId: $pmsClientId, environment: $this->environmentFor($pmsClientId), companyName: $companyName);
    }

    public function refreshAccessToken(QuickBooksConnection $connection): QuickBooksConnection
    {
        if (! $connection->refresh_token) {
            $connection->forceFill(['last_error' => 'Missing refresh token. Reconnect QuickBooks.'])->save();
            throw new RuntimeException('Missing QuickBooks refresh token.');
        }

        $environment = $connection->environment();

        $response = Http::asForm()
            ->acceptJson()
            ->withBasicAuth($this->clientId($environment), $this->clientSecret($environment))
            ->post(config('services.quickbooks.token_url').'/oauth2/v1/tokens/bearer', [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
            ]);

        if ($response->failed()) {
            $connection->forceFill(['last_error' => 'Token refresh failed — QuickBooks authorization may have been revoked. Reconnect QuickBooks.'])->save();
            throw new RuntimeException('Failed to refresh QuickBooks token. Please reconnect.');
        }

        return $this->persistTokens($response->json(), $connection);
    }

    public function ensureValidAccessToken(?QuickBooksConnection $connection = null): QuickBooksConnection
    {
        if (! $connection) {
            $connections = QuickBooksConnection::query()
                ->where('provider', 'quickbooks')
                ->orderByDesc('updated_at')
                ->get();

            if ($connections->count() === 1) {
                $connection = $connections->first();
            } elseif ($connections->isEmpty()) {
                throw new RuntimeException('No QuickBooks connection found. Complete the OAuth flow first.');
            } else {
                throw new RuntimeException('Multiple QuickBooks connections found. Provide a PMS client identifier.');
            }
        }

        if ($connection->token_expires_at && $connection->token_expires_at->subMinutes(2)->isPast()) {
            return $this->refreshAccessToken($connection);
        }

        return $connection;
    }

    public function validateState(?string $state): array
    {
        return $this->state->validate($state);
    }

    /**
     * Revoke this connection's OAuth token with Intuit, which invalidates it immediately
     * instead of leaving it to expire naturally (refresh tokens are long-lived).
     */
    public function revokeToken(QuickBooksConnection $connection): bool
    {
        $token = $connection->refresh_token ?: $connection->access_token;

        if (! $token) {
            return true;
        }

        $environment = $connection->environment();

        $response = Http::acceptJson()
            ->withBasicAuth($this->clientId($environment), $this->clientSecret($environment))
            ->post('https://developer.api.intuit.com/v2/oauth2/tokens/revoke', [
                'token' => $token,
            ]);

        if ($response->failed()) {
            logger()->warning('QuickBooks: token revocation failed', [
                'pms_client_id' => $connection->pms_client_id,
                'status'        => $response->status(),
                'body'          => $response->json() ?? $response->body(),
            ]);

            return false;
        }

        return true;
    }

    private function persistTokens(
        array $payload,
        ?QuickBooksConnection $connection = null,
        ?string $realmId = null,
        ?string $pmsClientId = null,
        ?string $environment = null,
        ?string $companyName = null,
    ): QuickBooksConnection {
        $pmsClientId = $pmsClientId ?: $connection?->pms_client_id;

        if (! $pmsClientId) {
            throw new RuntimeException('Missing PMS client identifier for QuickBooks connection.');
        }

        $clientExists = Client::query()->where('pms_client_id', $pmsClientId)->exists();

        if (! $clientExists) {
            throw new RuntimeException("Unknown client for PMS client identifier [{$pmsClientId}].");
        }

        $connection ??= QuickBooksConnection::query()->firstOrNew([
            'provider'      => 'quickbooks',
            'pms_client_id' => $pmsClientId,
        ]);

        $existingMeta = (array) ($connection->meta ?? []);
        $realmId = $realmId ?: ($existingMeta['realm_id'] ?? '');

        $connection->fill([
            'provider'         => 'quickbooks',
            'pms_client_id'    => $pmsClientId,
            'access_token'     => $payload['access_token'] ?? null,
            'refresh_token'    => $payload['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at' => isset($payload['expires_in'])
                ? now()->addSeconds((int) $payload['expires_in'])
                : $connection->token_expires_at,
            'meta'             => array_merge($existingMeta, array_filter(['realm_id' => $realmId, 'environment' => $environment, 'company_name' => $companyName])),
            'last_error'       => null,
        ]);

        $connection->save();

        return $connection->fresh();
    }

    private function environmentFor(string $pmsClientId): string
    {
        $client = Client::query()->where('pms_client_id', $pmsClientId)->first();

        return (string) data_get($client?->gateway_credentials, 'environment', 'sandbox');
    }

    private function clientId(string $environment): string
    {
        return $this->requiredConfig($environment === 'production' ? 'client_id_production' : 'client_id');
    }

    private function clientSecret(string $environment): string
    {
        return $this->requiredConfig($environment === 'production' ? 'client_secret_production' : 'client_secret');
    }

    private function redirectUri(): string
    {
        return $this->requiredConfig('redirect_uri');
    }

    private function requiredConfig(string $key): string
    {
        $value = config("services.quickbooks.{$key}");

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Missing QuickBooks configuration value [{$key}].");
        }

        return $value;
    }
}
