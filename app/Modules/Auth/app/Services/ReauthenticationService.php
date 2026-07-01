<?php

namespace Modules\Auth\Services;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Enums\AuditEventType;
use Modules\Auth\Enums\OperationOutcome;

class ReauthenticationService
{
    public function __construct(
        private readonly MerchantSessionService $merchantSessionService,
        private readonly AuthAuditService $authAuditService,
    ) {}

    public function verify($clientAccount, $session, string $password, string $ipAddress): void
    {

        $key = $this->rateLimitKey($clientAccount->id, $ipAddress);

        if (RateLimiter::tooManyAttempts($key, config('rate_limits.reauth.max_attempts', 5))) {
            throw ValidationException::withMessages([
                'password' => 'Too many password confirmation attempts. Please try again later.',
            ]);
        }

        if (!Hash::check($password, $clientAccount->password_hash)) {
            RateLimiter::hit(
                $key,
                config('rate_limits.reauth.decay_minutes', 15) * 60
            );

            $this->authAuditService->log(
                [
                    'client_account_id' => $clientAccount->id,
                    'event_type' => AuditEventType::REAUTH_FAILURE,
                    'outcome' => OperationOutcome::FAILURE,
                    'metadata' => [
                        'ip_address' => $ipAddress,
                    ]
                ]
            );

            throw ValidationException::withMessages([
                'password' => 'The password is incorrect.',
            ]);
        }

        RateLimiter::clear($key);

        $this->merchantSessionService->markReauthenticated($session);

        $this->authAuditService->log(
            [
                'client_account_id' => $clientAccount->id,
                'event_type' => AuditEventType::REAUTH_SUCCESS,
                'outcome' => OperationOutcome::SUCCESS,
                'metadata' => [
                    'ip_address' => $ipAddress,
                ]
            ]
        );
    }

    private function rateLimitKey(int|string $clientAccountId, string $ipAddress): string
    {
        return 'reauth:' . $clientAccountId . ':' . $ipAddress;
    }
}
