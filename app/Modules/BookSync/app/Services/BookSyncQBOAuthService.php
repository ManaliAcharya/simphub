<?php

namespace Modules\BookSync\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Modules\BookSync\Models\BookSyncMerchant;
use RuntimeException;

/**
 * Handles QB OAuth2 for BookSync merchants using the BookSync-specific
 * Intuit app credentials (BOOKSYNC_QB_CLIENT_ID / BOOKSYNC_QB_CLIENT_SECRET).
 */
class BookSyncQBOAuthService
{
    public function authorizationUrl(string $setupToken): string
    {
        return $this->oauthBaseUrl() . '/connect/oauth2?' . http_build_query([
            'client_id'     => $this->clientId(),
            'response_type' => 'code',
            'scope'         => 'com.intuit.quickbooks.accounting',
            'redirect_uri'  => $this->redirectUri(),
            'state'         => $this->makeState($setupToken),
        ]);
    }

    public function exchangeCode(string $code, string $realmId, string $setupToken): BookSyncMerchant
    {
        if ($realmId === '') {
            throw new RuntimeException('QuickBooks realmId is missing from the OAuth callback.');
        }

        $merchant = BookSyncMerchant::where('setup_token', $setupToken)->firstOrFail();

        $tokens = Http::asForm()
            ->acceptJson()
            ->withBasicAuth($this->clientId(), $this->clientSecret())
            ->post($this->tokenUrl() . '/oauth2/v1/tokens/bearer', [
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => $this->redirectUri(),
            ])
            ->throw()
            ->json();

        $companyName = $this->fetchCompanyName($tokens['access_token'], $realmId);

        $merchant->forceFill([
            'qb_realm_id'         => $realmId,
            'qb_company_name'     => $companyName,
            'qb_access_token'     => $tokens['access_token'],
            'qb_refresh_token'    => $tokens['refresh_token'] ?? null,
            'qb_token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
            'qb_connected_at'     => now(),
        ])->save();

        return $merchant->fresh();
    }

    public function ensureValidToken(BookSyncMerchant $merchant): BookSyncMerchant
    {
        if (! $merchant->qb_access_token) {
            throw new RuntimeException('Merchant has no QuickBooks access token. Complete the OAuth setup first.');
        }

        if ($merchant->qb_token_expires_at && $merchant->qb_token_expires_at->subMinutes(2)->isPast()) {
            return $this->refreshToken($merchant);
        }

        return $merchant;
    }

    public function refreshToken(BookSyncMerchant $merchant): BookSyncMerchant
    {
        if (! $merchant->qb_refresh_token) {
            $merchant->forceFill(['status' => 'qb_token_expired'])->save();
            throw new RuntimeException('QuickBooks refresh token is missing. Merchant must reconnect QuickBooks.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->withBasicAuth($this->clientId(), $this->clientSecret())
            ->post($this->tokenUrl() . '/oauth2/v1/tokens/bearer', [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $merchant->qb_refresh_token,
            ]);

        if ($response->failed()) {
            $merchant->forceFill(['status' => 'qb_token_expired'])->save();
            throw new RuntimeException('Failed to refresh QuickBooks token. Merchant must reconnect.');
        }

        $tokens = $response->throw()->json();

        $merchant->forceFill([
            'qb_access_token'     => $tokens['access_token'],
            'qb_refresh_token'    => $tokens['refresh_token'] ?? $merchant->qb_refresh_token,
            'qb_token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
            'status'              => 'active',
        ])->save();

        return $merchant->fresh();
    }

    public function validateState(?string $state): string
    {
        if (! $state) {
            throw new RuntimeException('Missing OAuth state parameter.');
        }

        $payload = json_decode(Crypt::decryptString($state), true, 512, JSON_THROW_ON_ERROR);

        if (! isset($payload['setup_token'], $payload['issued_at'])) {
            throw new RuntimeException('Invalid OAuth state payload.');
        }

        if (\Carbon\CarbonImmutable::parse($payload['issued_at'])->addMinutes(15)->isPast()) {
            throw new RuntimeException('OAuth state has expired.');
        }

        return $payload['setup_token'];
    }

    private function makeState(string $setupToken): string
    {
        return Crypt::encryptString(json_encode([
            'setup_token' => $setupToken,
            'issued_at'   => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    private function fetchCompanyName(string $accessToken, string $realmId): string
    {
        $baseUrl = rtrim(config('services.quickbooks.base_url', 'https://quickbooks.api.intuit.com'), '/');
        $minorVersion = config('services.quickbooks.minor_version', '65');

        $response = Http::acceptJson()
            ->withToken($accessToken)
            ->get("{$baseUrl}/v3/company/{$realmId}/companyinfo/{$realmId}", [
                'minorversion' => $minorVersion,
            ]);

        if ($response->failed()) {
            return '';
        }

        return (string) data_get($response->json(), 'CompanyInfo.CompanyName', '');
    }

    private function oauthBaseUrl(): string
    {
        return rtrim(config('services.quickbooks.oauth_base_url', 'https://appcenter.intuit.com'), '/');
    }

    private function tokenUrl(): string
    {
        return rtrim(config('services.quickbooks.token_url', 'https://oauth.platform.intuit.com'), '/');
    }

    private function clientId(): string
    {
        return $this->requiredBookSyncConfig('qb_client_id');
    }

    private function clientSecret(): string
    {
        return $this->requiredBookSyncConfig('qb_client_secret');
    }

    private function redirectUri(): string
    {
        $uri = config('services.booksync.qb_redirect_uri');
        if (! is_string($uri) || trim($uri) === '') {
            throw new RuntimeException('Missing BookSync QB redirect URI [services.booksync.qb_redirect_uri].');
        }

        return $uri;
    }

    private function requiredBookSyncConfig(string $key): string
    {
        $value = config("services.booksync.{$key}");
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Missing BookSync configuration value [services.booksync.{$key}].");
        }

        return $value;
    }
}
