<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClioConnection;
use RuntimeException;
use Throwable;

class ClioOAuthService
{
    public function __construct(
        private readonly PmsOAuthStateService $state,
    ) {}

    public function authorizationUrl(string $pmsClientId): string
    {
        return config('services.clio.base_url').'/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->clientId(),
            'redirect_uri'  => $this->redirectUri(),
            'scope'         => config('services.clio.scope', 'openid'),
            'state'         => $this->state->make('clio', $pmsClientId),
        ]);
    }

    public function exchangeCode(string $code, string $pmsClientId): ClioConnection
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

        return $this->persistTokens($response->json(), pmsClientId: $pmsClientId);
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

    public function validateState(?string $state): array
    {
        return $this->state->validate($state);
    }

    /**
     * Per Clio's docs: POST /oauth/revoke on the separate auth.api.clio.com host,
     * Basic-auth'd with base64(client_id:client_secret), one "token" param per call.
     * Only the access token is documented, but revoking the refresh token too is
     * standard OAuth cleanup practice - each call is independent/best-effort so a
     * failure on one doesn't skip the other.
     */
    public function revokeToken(ClioConnection $connection): void
    {
        foreach (['access_token', 'refresh_token'] as $field) {
            $token = $connection->{$field};

            if (! $token) {
                continue;
            }

            try {
                Http::asForm()
                    ->withBasicAuth($this->clientId(), $this->clientSecret())
                    ->post('https://auth.api.clio.com/oauth/revoke', [
                        'token' => $token,
                    ])
                    ->throw();
            } catch (Throwable $e) {
                Log::warning('Clio: failed to revoke token.', [
                    'pms_client_id' => $connection->pms_client_id,
                    'token_field'   => $field,
                    'error'         => $e->getMessage(),
                ]);
            }
        }
    }

    private function persistTokens(array $payload, ?ClioConnection $connection = null, ?string $pmsClientId = null): ClioConnection
    {
        $pmsClientId = $pmsClientId ?: $connection?->pms_client_id;

        if (! $pmsClientId) {
            throw new RuntimeException('Missing PMS client identifier for Clio connection.');
        }

        $clientExists = Client::query()
            ->where('pms_client_id', $pmsClientId)
            ->exists();

        if (! $clientExists) {
            throw new RuntimeException("Unknown client for PMS client identifier [{$pmsClientId}].");
        }

        $connection ??= ClioConnection::query()->firstOrNew([
            'provider' => 'clio',
            'pms_client_id' => $pmsClientId,
        ]);

        $connection->fill([
            'provider' => 'clio',
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

    private function requiredConfig(string $key): string
    {
        $value = config("services.clio.{$key}");

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Missing Clio configuration value [{$key}].");
        }

        return $value;
    }
}
