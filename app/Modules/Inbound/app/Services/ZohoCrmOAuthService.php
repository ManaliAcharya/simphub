<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\PmsConnection;
use RuntimeException;

/**
 * OAuth for Zoho CRM. Same Zoho accounts servers as Zoho Books (picked by the
 * client's region), but its own provider key, scopes and redirect URI.
 */
class ZohoCrmOAuthService
{
    public const PROVIDER = 'zohocrm';

    public function __construct(
        private readonly PmsOAuthStateService $state,
        private readonly ZohoRegionResolver $regions,
    ) {}

    public function authorizationUrl(string $pmsClientId): string
    {
        return rtrim($this->regions->accountsBaseUrlForClientId($pmsClientId), '/').'/oauth/v2/auth?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->requiredConfig('client_id'),
            'redirect_uri' => $this->requiredConfig('redirect_uri'),
            'scope' => $this->requiredConfig('scope'),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $this->state->make(self::PROVIDER, $pmsClientId),
        ]);
    }

    public function exchangeCode(string $code, string $pmsClientId): PmsConnection
    {
        $payload = Http::asForm()
            ->acceptJson()
            ->post(rtrim($this->regions->accountsBaseUrlForClientId($pmsClientId), '/').'/oauth/v2/token', [
                'grant_type' => 'authorization_code',
                'client_id' => $this->requiredConfig('client_id'),
                'client_secret' => $this->requiredConfig('client_secret'),
                'redirect_uri' => $this->requiredConfig('redirect_uri'),
                'code' => $code,
            ])
            ->throw()
            ->json();

        // Zoho answers a bad/expired code with HTTP 200 and an "error" key.
        if (! empty($payload['error']) || empty($payload['access_token'])) {
            throw new RuntimeException('Zoho CRM authorization failed: '.($payload['error'] ?? 'no access token returned').'.');
        }

        if (empty($payload['refresh_token'])) {
            throw new RuntimeException('Zoho CRM did not return a refresh token. Please connect again.');
        }

        return $this->persistTokens($payload, pmsClientId: $pmsClientId);
    }

    public function ensureValidAccessToken(?PmsConnection $connection): PmsConnection
    {
        if (! $connection) {
            throw new RuntimeException('Zoho CRM is not connected for this client.');
        }

        if ($connection->token_expires_at && $connection->token_expires_at->subMinutes(2)->isPast()) {
            return $this->refreshAccessToken($connection);
        }

        return $connection;
    }

    public function refreshAccessToken(PmsConnection $connection): PmsConnection
    {
        if (! $connection->refresh_token) {
            throw new RuntimeException('Missing Zoho CRM refresh token. Reconnect Zoho CRM.');
        }

        $payload = Http::asForm()
            ->acceptJson()
            ->post(rtrim($this->regions->accountsBaseUrlForConnection($connection), '/').'/oauth/v2/token', [
                'grant_type' => 'refresh_token',
                'client_id' => $this->requiredConfig('client_id'),
                'client_secret' => $this->requiredConfig('client_secret'),
                'refresh_token' => $connection->refresh_token,
            ])
            ->throw()
            ->json();

        if (! empty($payload['error']) || empty($payload['access_token'])) {
            $message = 'Zoho CRM token refresh failed: '.($payload['error'] ?? 'no access token returned').'. Reconnect Zoho CRM.';
            $connection->forceFill(['last_error' => $message])->save();

            throw new RuntimeException($message);
        }

        return $this->persistTokens($payload, $connection);
    }

    private function persistTokens(array $payload, ?PmsConnection $connection = null, ?string $pmsClientId = null): PmsConnection
    {
        $pmsClientId = $pmsClientId ?: $connection?->pms_client_id;

        if (! $pmsClientId || ! Client::query()->where('pms_client_id', $pmsClientId)->exists()) {
            throw new RuntimeException("Unknown client for PMS client identifier [{$pmsClientId}].");
        }

        $connection ??= PmsConnection::query()->firstOrNew([
            'provider' => self::PROVIDER,
            'pms_client_id' => $pmsClientId,
        ]);

        $connection->fill([
            'access_token' => $payload['access_token'],
            'refresh_token' => $payload['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at' => isset($payload['expires_in'])
                ? now()->addSeconds((int) $payload['expires_in'])
                : $connection->token_expires_at,
            'meta' => array_filter([
                ...((array) $connection->meta),
                'api_domain' => $payload['api_domain'] ?? data_get($connection->meta, 'api_domain'),
            ], static fn ($value) => $value !== null),
            'last_error' => null,
        ]);

        $connection->save();

        return $connection->fresh();
    }

    private function requiredConfig(string $key): string
    {
        $value = config("services.zohocrm.{$key}");

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Missing Zoho CRM configuration value [{$key}].");
        }

        return $value;
    }
}
