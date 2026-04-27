<?php

namespace Modules\Inbound\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class PmsOAuthStateService
{
    public function make(string $provider, string $pmsClientId): string
    {
        return Crypt::encryptString(json_encode([
            'provider' => $provider,
            'issued_at' => now()->toIso8601String(),
            'pms_client_id' => $pmsClientId,
        ], JSON_THROW_ON_ERROR));
    }

    public function validate(?string $state): array
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

        if (! isset($payload['pms_client_id']) || ! is_string($payload['pms_client_id']) || trim($payload['pms_client_id']) === '') {
            throw new RuntimeException('OAuth state is missing PMS client identifier.');
        }

        if (! isset($payload['provider']) || ! is_string($payload['provider']) || trim($payload['provider']) === '') {
            throw new RuntimeException('OAuth state is missing PMS provider.');
        }

        return $payload;
    }
}
