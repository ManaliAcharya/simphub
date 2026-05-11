<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\LawcusConnection;
use RuntimeException;

class LawcusOAuthService
{
    public function __construct(
        private readonly PmsOAuthStateService $state,
    ) {}

    public function authorizationUrl(string $pmsClientId): string
    {
        return config('services.lawcus.base_url').'/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->clientId(),
            'redirect_uri'  => $this->redirectUri(),
            'scope'         => config('services.lawcus.scope', 'openid'),
            'state'         => $this->state->make('lawcus', $pmsClientId),
        ]);
    }

    public function exchangeCode(string $code, string $pmsClientId): LawcusConnection
    {
        $response = Http::asForm()
            ->acceptJson()
            ->post(config('services.lawcus.base_url').'/oauth/token', [
                'grant_type'    => 'authorization_code',
                'client_id'     => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'redirect_uri'  => $this->redirectUri(),
                'code'          => $code,
            ])
            ->throw();

        return $this->persistTokens($response->json(), pmsClientId: $pmsClientId);
    }

    public function refreshAccessToken(LawcusConnection $connection): LawcusConnection
    {
        if (! $connection->refresh_token) {
            throw new RuntimeException('Missing Lawcus refresh token.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->post(config('services.lawcus.base_url').'/oauth/token', [
                'grant_type'    => 'refresh_token',
                'client_id'     => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'refresh_token' => $connection->refresh_token,
            ])
            ->throw();

        return $this->persistTokens($response->json(), $connection);
    }

    public function ensureValidAccessToken(?LawcusConnection $connection = null): LawcusConnection
    {
        if (! $connection) {
            $connections = LawcusConnection::query()
                ->where('provider', 'lawcus')
                ->orderByDesc('updated_at')
                ->get();

            if ($connections->count() === 1) {
                $connection = $connections->first();
            } elseif ($connections->isEmpty()) {
                throw new RuntimeException('No Lawcus connection found. Complete the OAuth flow first.');
            } else {
                throw new RuntimeException('Multiple Lawcus connections found. Provide a PMS client identifier.');
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

    private function persistTokens(array $payload, ?LawcusConnection $connection = null, ?string $pmsClientId = null): LawcusConnection
    {
        $pmsClientId = $pmsClientId ?: $connection?->pms_client_id;

        if (! $pmsClientId) {
            throw new RuntimeException('Missing PMS client identifier for Lawcus connection.');
        }

        $clientExists = Client::query()
            ->where('pms_client_id', $pmsClientId)
            ->exists();

        if (! $clientExists) {
            throw new RuntimeException("Unknown client for PMS client identifier [{$pmsClientId}].");
        }

        $connection ??= LawcusConnection::query()->firstOrNew([
            'provider'       => 'lawcus',
            'pms_client_id'  => $pmsClientId,
        ]);

        $connection->fill([
            'provider'          => 'lawcus',
            'pms_client_id'     => $pmsClientId,
            'access_token'      => $payload['access_token'] ?? null,
            'refresh_token'     => $payload['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at'  => isset($payload['expires_in'])
                ? now()->addSeconds((int) $payload['expires_in'])
                : $connection->token_expires_at,
            'last_error'        => null,
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
        $value = config("services.lawcus.{$key}");

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Missing Lawcus configuration value [{$key}].");
        }

        return $value;
    }
}
