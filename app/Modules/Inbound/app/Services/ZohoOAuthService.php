<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\PmsConnection;
use RuntimeException;

class ZohoOAuthService
{
    public function __construct(
        private readonly PmsOAuthStateService $state,
        private readonly ZohoApiClient $client,
        private readonly ZohoRegionResolver $regions,
    ) {}

    public function authorizationUrl(string $pmsClientId): string
    {
        return rtrim($this->regions->accountsBaseUrlForClientId($pmsClientId), '/').'/oauth/v2/auth?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'scope' => $this->scope(),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $this->state->make('zoho', $pmsClientId),
        ]);
    }

    public function exchangeCode(string $code, string $pmsClientId): PmsConnection
    {
        $response = Http::asForm()
            ->acceptJson()
            ->post(rtrim($this->regions->accountsBaseUrlForClientId($pmsClientId), '/').'/oauth/v2/token', [
                'grant_type' => 'authorization_code',
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'redirect_uri' => $this->redirectUri(),
                'code' => $code,
            ])
            ->throw();

        $connection = $this->persistTokens($response->json(), pmsClientId: $pmsClientId);
        $organizationsPayload = $this->client->fetchOrganizations($connection);
        $organizations = $this->extractOrganizations($organizationsPayload);
        $defaultOrganization = $this->resolveDefaultOrganization($organizations);
        $defaultOrganizationId = (string) (
            data_get($defaultOrganization, 'organization_id')
            ?? config('services.zoho.organization_id')
            ?? ''
        );
        $defaultOrganizationName = (string) (
            data_get($defaultOrganization, 'name')
            ?? config('services.zoho.organization_name')
            ?? ''
        );

        if ($organizations === [] && $defaultOrganizationId !== '') {
            $organizations = [[
                'organization_id' => $defaultOrganizationId,
                'name' => $defaultOrganizationName,
                'is_default_org' => true,
            ]];
        }

        $connection->forceFill([
            'meta' => array_filter([
                ...((array) $connection->meta),
                'organizations' => $organizations,
                'organizations_payload' => $organizationsPayload,
                'default_organization_id' => $defaultOrganizationId !== '' ? $defaultOrganizationId : null,
                'default_organization_name' => $defaultOrganizationName !== '' ? $defaultOrganizationName : null,
            ], static fn ($value) => $value !== null),
            'last_error' => $defaultOrganizationId === '' ? 'Zoho organization id could not be resolved during authentication.' : null,
        ])->save();

        return $connection->fresh();
    }

    public function refreshAccessToken(PmsConnection $connection): PmsConnection
    {
        if (! $connection->refresh_token) {
            throw new RuntimeException('Missing Zoho refresh token.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->post(rtrim($this->regions->accountsBaseUrlForConnection($connection), '/').'/oauth/v2/token', [
                'grant_type' => 'refresh_token',
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'refresh_token' => $connection->refresh_token,
            ])
            ->throw();

        return $this->persistTokens($response->json(), $connection);
    }

    public function ensureValidAccessToken(?PmsConnection $connection = null): PmsConnection
    {
        if (! $connection) {
            $connection = PmsConnection::query()
                ->where('provider', 'zoho')
                ->latest('updated_at')
                ->first();
        }

        if (! $connection) {
            throw new RuntimeException('No Zoho connection found. Complete the OAuth flow first.');
        }

        if ($connection->token_expires_at && $connection->token_expires_at->subMinutes(2)->isPast()) {
            return $this->refreshAccessToken($connection);
        }

        return $connection;
    }

    private function persistTokens(array $payload, ?PmsConnection $connection = null, ?string $pmsClientId = null): PmsConnection
    {
        $pmsClientId = $pmsClientId ?: $connection?->pms_client_id;

        if (! $pmsClientId) {
            throw new RuntimeException('Missing PMS client identifier for Zoho connection.');
        }

        $clientExists = Client::query()
            ->where('pms_client_id', $pmsClientId)
            ->exists();

        if (! $clientExists) {
            throw new RuntimeException("Unknown client for PMS client identifier [{$pmsClientId}].");
        }

        $connection ??= PmsConnection::query()->firstOrNew([
            'provider' => 'zoho',
            'pms_client_id' => $pmsClientId,
        ]);

        $connection->fill([
            'provider' => 'zoho',
            'pms_client_id' => $pmsClientId,
            'access_token' => $payload['access_token'] ?? null,
            'refresh_token' => $payload['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at' => isset($payload['expires_in'])
                ? now()->addSeconds((int) $payload['expires_in'])
                : $connection->token_expires_at,
            'last_error' => null,
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

    private function scope(): string
    {
        return $this->requiredConfig('scope');
    }

    private function requiredConfig(string $key): string
    {
        $value = config("services.zoho.{$key}");

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Missing Zoho configuration value [{$key}].");
        }

        return $value;
    }

    private function extractOrganizations(array $payload): array
    {
        $organizations = $payload['organizations']
            ?? data_get($payload, 'data.organizations')
            ?? data_get($payload, 'organization')
            ?? data_get($payload, 'data.organization')
            ?? [];

        if (! is_array($organizations)) {
            return [];
        }

        if (array_is_list($organizations)) {
            return array_values(array_filter($organizations, 'is_array'));
        }

        return [$organizations];
    }

    private function resolveDefaultOrganization(array $organizations): ?array
    {
        $configuredOrganizationId = (string) (config('services.zoho.organization_id') ?? '');

        if ($configuredOrganizationId !== '') {
            $match = collect($organizations)->first(fn (array $organization) => (string) ($organization['organization_id'] ?? '') === $configuredOrganizationId);
            if (is_array($match)) {
                return $match;
            }
        }

        $default = collect($organizations)->first(function (array $organization): bool {
            $flag = $organization['is_default_org'] ?? false;

            return $flag === true || $flag === 'true' || $flag === 1 || $flag === '1';
        });

        if (is_array($default)) {
            return $default;
        }

        return $organizations[0] ?? null;
    }
}
