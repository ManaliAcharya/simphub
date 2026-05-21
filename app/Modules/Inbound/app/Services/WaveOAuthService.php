<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\WaveConnection;
use RuntimeException;

class WaveOAuthService
{
    public function __construct(
        private readonly PmsOAuthStateService $state,
    ) {}

    public function authorizationUrl(string $pmsClientId): string
    {
        return config('services.wave.base_url').'/oauth2/authorize/?'.http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->clientId(),
            'redirect_uri'  => $this->redirectUri(),
            'scope'         => config('services.wave.scope', 'account:*'),
            'state'         => $this->state->make('wave', $pmsClientId),
        ]);
    }

    public function exchangeCode(string $code, string $pmsClientId): WaveConnection
    {
        $response = Http::asForm()
            ->acceptJson()
            ->post(config('services.wave.base_url').'/oauth2/token/', [
                'grant_type'    => 'authorization_code',
                'client_id'     => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'redirect_uri'  => $this->redirectUri(),
                'code'          => $code,
            ])
            ->throw();

        return $this->persistTokens($response->json(), pmsClientId: $pmsClientId);
    }

    public function refreshAccessToken(WaveConnection $connection): WaveConnection
    {
        if (! $connection->refresh_token) {
            throw new RuntimeException('Missing Wave refresh token.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->post(config('services.wave.base_url').'/oauth2/token/', [
                'grant_type'    => 'refresh_token',
                'client_id'     => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'refresh_token' => $connection->refresh_token,
            ])
            ->throw();

        return $this->persistTokens($response->json(), $connection);
    }

    public function ensureValidAccessToken(?WaveConnection $connection = null): WaveConnection
    {
        if (! $connection) {
            $connections = WaveConnection::query()
                ->where('provider', 'wave')
                ->orderByDesc('updated_at')
                ->get();

            if ($connections->count() === 1) {
                $connection = $connections->first();
            } elseif ($connections->isEmpty()) {
                throw new RuntimeException('No Wave connection found. Complete the OAuth flow first.');
            } else {
                throw new RuntimeException('Multiple Wave connections found. Provide a PMS client identifier.');
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

    private function persistTokens(array $payload, ?WaveConnection $connection = null, ?string $pmsClientId = null): WaveConnection
    {
        $pmsClientId = $pmsClientId ?: $connection?->pms_client_id;

        if (! $pmsClientId) {
            throw new RuntimeException('Missing PMS client identifier for Wave connection.');
        }

        $clientExists = Client::query()
            ->where('pms_client_id', $pmsClientId)
            ->exists();

        if (! $clientExists) {
            throw new RuntimeException("Unknown client for PMS client identifier [{$pmsClientId}].");
        }

        $connection ??= WaveConnection::query()->firstOrNew([
            'provider'      => 'wave',
            'pms_client_id' => $pmsClientId,
        ]);

        $connection->fill([
            'provider'          => 'wave',
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
        $value = config("services.wave.{$key}");

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Missing Wave configuration value [{$key}].");
        }

        return $value;
    }
}
