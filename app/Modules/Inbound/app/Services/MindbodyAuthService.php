<?php

namespace Modules\Inbound\Services;

use Modules\Inbound\Models\MindbodySite;
use RuntimeException;

class MindbodyAuthService
{
    public function __construct(
        private readonly MindbodyApiClient $api,
    ) {}

    /**
     * Ensure the site has a valid, non-expired staff token.
     * Issues a new one or renews if close to expiry.
     */
    public function ensureValidToken(MindbodySite $site): MindbodySite
    {
        if (! $site->isTokenExpired()) {
            return $site;
        }

        if ($site->staff_token) {
            try {
                return $this->renew($site);
            } catch (\Throwable) {
                // Renewal failed — fall through and re-issue
            }
        }

        return $this->issue($site);
    }

    public function issue(MindbodySite $site): MindbodySite
    {
        $username = $site->staffUsername();
        $password = $site->staffPassword();

        if ($username === '' || $password === '') {
            throw new RuntimeException('Mindbody staff credentials are not configured.');
        }

        $response = $this->api->issueStaffToken($site->site_id, $username, $password);

        return $this->persistToken($site, $response);
    }

    public function renew(MindbodySite $site): MindbodySite
    {
        if (! $site->staff_token) {
            throw new RuntimeException('No staff token to renew.');
        }

        $response = $this->api->renewStaffToken($site->site_id, $site->staff_token);

        return $this->persistToken($site, $response);
    }

    /**
     * Test credentials without persisting. Returns true if valid.
     */
    public function testCredentials(string $siteId, string $username, string $password): array
    {
        $response = $this->api->issueStaffToken($siteId, $username, $password);

        $token = $response['AccessToken'] ?? null;

        if (! $token) {
            throw new RuntimeException('Mindbody rejected the credentials. Check the site ID and staff username/password.');
        }

        return $response;
    }

    private function persistToken(MindbodySite $site, array $response): MindbodySite
    {
        $token     = (string) ($response['AccessToken'] ?? '');
        $expiresIn = (int) ($response['ExpiresIn'] ?? 3600);

        if ($token === '') {
            throw new RuntimeException('Mindbody did not return a valid access token.');
        }

        $site->forceFill([
            'staff_token'            => $token,
            'staff_token_expires_at' => now()->addSeconds($expiresIn),
            'last_error'             => null,
        ])->save();

        return $site->fresh();
    }
}
