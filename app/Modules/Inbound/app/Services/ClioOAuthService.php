<?php

namespace Modules\Inbound\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Inbound\Models\ClioConnection;
use RuntimeException;

class ClioOAuthService
{
    public function authorizationUrl(): string
    {
        return config('services.clio.base_url').'/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'state' => $this->makeState(),
        ]);
    }

    public function exchangeCode(string $code): ClioConnection
    {
        $response = Http::asForm()
            ->acceptJson()
            ->post(config('services.clio.base_url').'/oauth/token', [
                'grant_type' => 'authorization_code',
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'redirect_uri' => $this->redirectUri(),
                'code' => $code,
            ])
            ->throw();

        return $this->persistTokens($response->json());
    }

    public function refreshAccessToken(ClioConnection $connection): ClioConnection
    {
        if (! $connection->refresh_token) {
            throw new RuntimeException('Missing Clio refresh token.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->post(config('services.clio.base_url').'/oauth/token', [
                'grant_type' => 'refresh_token',
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'refresh_token' => $connection->refresh_token,
            ])
            ->throw();

        return $this->persistTokens($response->json(), $connection);
    }

    public function ensureValidAccessToken(?ClioConnection $connection = null): ClioConnection
    {
        if (! $connection) {
            $connections = ClioConnection::query()
                ->where('provider', 'clio')
                ->orderByDesc('updated_at')
                ->get();

            if ($connections->count() === 1) {
                $connection = $connections->first();
            } elseif ($connections->isEmpty()) {
                throw new RuntimeException('No Clio connection found. Complete the OAuth flow first.');
            } else {
                throw new RuntimeException('Multiple Clio connections found. Provide a PMS client identifier.');
            }
        }

        if ($connection->token_expires_at && $connection->token_expires_at->subMinutes(2)->isPast()) {
            return $this->refreshAccessToken($connection);
        }

        return $connection;
    }

    public function validateState(?string $state): void
    {
        if (! $state) {
            throw new RuntimeException('Missing OAuth state.');
        }

        $payload = json_decode(Crypt::decryptString($state), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload) || ! isset($payload['issued_at'])) {
            throw new RuntimeException('Invalid OAuth state payload.');
        }

        if (CarbonImmutable::parse($payload['issued_at'])->addMinutes(10)->isPast()) {
            throw new RuntimeException('OAuth state has expired.');
        }
    }

    private function persistTokens(array $payload, ?ClioConnection $connection = null): ClioConnection
    {
        $connection ??= new ClioConnection([
            'provider' => 'clio',
            'pms_client_id' => (string) Str::uuid(),
        ]);

        $connection->fill([
            'provider' => 'clio',
            'pms_client_id' => $connection->pms_client_id ?: (string) Str::uuid(),
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

    private function makeState(): string
    {
        return Crypt::encryptString(json_encode([
            'issued_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
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
        $value = config("services.clio.{$key}");

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Missing Clio configuration value [{$key}].");
        }

        return $value;
    }
}
