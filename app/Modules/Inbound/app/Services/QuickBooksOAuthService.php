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
        return config('services.quickbooks.oauth_base_url').'/connect/oauth2?'.http_build_query([
            'client_id'     => $this->clientId(),
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

        return $this->createConnection($this->exchangeCodeTokens($code), $realmId, $pmsClientId);
    }

    public function exchangeCodeTokens(string $code): array
    {
        return Http::asForm()
            ->acceptJson()
            ->withBasicAuth($this->clientId(), $this->clientSecret())
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

    public function createConnection(array $tokenData, string $realmId, string $pmsClientId): QuickBooksConnection
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

        return $this->persistTokens($tokenData, realmId: $realmId, pmsClientId: $pmsClientId);
    }

    public function refreshAccessToken(QuickBooksConnection $connection): QuickBooksConnection
    {
        if (! $connection->refresh_token) {
            throw new RuntimeException('Missing QuickBooks refresh token.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->withBasicAuth($this->clientId(), $this->clientSecret())
            ->post(config('services.quickbooks.token_url').'/oauth2/v1/tokens/bearer', [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
            ])
            ->throw();

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

    private function persistTokens(
        array $payload,
        ?QuickBooksConnection $connection = null,
        ?string $realmId = null,
        ?string $pmsClientId = null,
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
            'meta'             => array_merge($existingMeta, array_filter(['realm_id' => $realmId])),
            'last_error'       => null,
        ]);

        $connection->save();

        return $connection->fresh();
    }

    private function clientId(): string
    {
        return $this->requiredConfig('client_id');
    }

    private function clientSecret(): string
    {
        return $this->requiredConfig('client_secret');
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
