<?php

namespace Modules\Auth\Services;

use App\Support\Contracts\Integrations\CaptchaVerifierInterface;
use App\Support\Integrations\GeoIp\GeoIpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Enums\AuditEventType;
use Modules\Auth\Enums\OperationOutcome;
use Modules\Auth\Jobs\SendAccountLockedEmailJob;
use Modules\Auth\Jobs\SendAccountSuspendedEmailJob;
use Modules\Auth\Jobs\SendNewDeviceLoginEmailJob;
use Modules\Auth\Repositories\ClientAccountRepository;
use Modules\Auth\Repositories\LoginAttemptRepository;
use Modules\Auth\Repositories\MerchantSessionRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthService
{
    private const int EMAIL_LOCK_LIMIT = 5;
    private const int IP_CAPTCHA_LIMIT = 10;
    private const int IP_BLOCK_LIMIT = 20;
    private const int WINDOW_MINUTES = 15;
    private const int DEVICE_LOOKBACK_DAYS = 30;
    private const int MAX_LOCKOUTS_BEFORE_SUSPEND = 3;
    private const int LOCKOUT_WINDOW_HOURS = 24;

    public function __construct(
        private readonly ClientAccountRepository $clientAccountRepository,
        private readonly LoginAttemptRepository $loginAttemptRepository,
        private readonly ClientAccountService $clientAccountService,
        private readonly MerchantSessionService $merchantSessionService,
        private readonly AuthAuditService $authAuditService,
        private readonly CaptchaVerifierInterface $captchaVerifier,
        private readonly GeoIpService $geoIpService,
        private readonly MerchantSessionRepository $merchantSessionRepository
    ) {}

    public function login(array $data): array
    {
        $email = Str::lower(trim($data['email']));
        $password = (string) $data['password'];
        $ipAddress = $data['ip_address'] ?? null;
        $userAgent = $data['user_agent'] ?? null;
        $captchaToken = $data['cf-turnstile-response'] ?? null;

        $this->checkRateLimits($email, $ipAddress, $captchaToken);

        $account = $this->clientAccountRepository->findByEmail($email);
        if (! $account) {

            throw ValidationException::withMessages([
                'email' => 'Incorrect email or password.',
            ]);
        }

        // Check suspended account
        if ($account->is_suspended) {

            $this->authAuditService->log([
                'client_account_id' => $account->id,
                'event_type' => AuditEventType::LOGIN_BLOCKED->value,
                'outcome' => OperationOutcome::FAILURE->value,
                'failure_reason' => 'account_suspended',
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata' => [
                    'suspended_reason' => $account->suspended_reason,
                    'suspended_at' => $account->suspended_at,
                ],
            ]);


            throw ValidationException::withMessages([
                'email' =>
                'Account suspended. Please contact support.',
            ]);
        }


        // Check active status
        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'email' => 'Account disabled.',
            ]);
        }


        // Now check password
        if (
            empty($account->password_hash) ||
            ! Hash::check($password, $account->password_hash)
        ) {

            $this->recordFailedLogin(
                email: $email,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                clientAccountId: $account->id
            );

            throw ValidationException::withMessages([
                'email' => 'Incorrect email or password.',
            ]);
        }

        return DB::transaction(function () use ($account, $email, $ipAddress, $userAgent) {
            $rawSessionToken = $this->generateToken();
            $deviceFingerprint = $this->makeDeviceFingerprint($ipAddress, $userAgent);

            $isNewDevice = ! $this->merchantSessionService->hasRecentDeviceFingerprint(
                clientAccountId: $account->id,
                deviceFingerprint: $deviceFingerprint,
                days: self::DEVICE_LOOKBACK_DAYS
            );

            $session = $this->merchantSessionService->createMerchantSession([
                'client_account_id' => $account->id,
                'raw_session_token' => $rawSessionToken,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'device_fingerprint' => $deviceFingerprint,
            ]);

            $this->clientAccountService->updateLoginInfo($account, [
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);

            $this->loginAttemptRepository->create([
                'email_lower' => $email,
                'ip_address' => $ipAddress,
                'outcome' => OperationOutcome::SUCCESS->value,
                'attempted_at' => now(),
            ]);

            $this->authAuditService->log([
                'client_account_id' => $account->id,
                'event_type' => AuditEventType::LOGIN_SUCCESS->value,
                'outcome' => OperationOutcome::SUCCESS->value,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata' => [
                    'session_id' => $session->getKey(),
                    'new_device' => $isNewDevice,
                ],
            ]);

            if ($isNewDevice) {
                $this->handleNewDeviceLogin(
                    account: $account,
                    sessionId: $session->getKey(),
                    ipAddress: $ipAddress,
                    userAgent: $userAgent
                );
            }

            return [
                'raw_session_token' => $rawSessionToken,
                'account' => $account,
            ];
        });
    }

    public function shouldShowCaptcha(?string $ipAddress): bool
    {
        if (! $ipAddress) {
            return false;
        }

        $ipFailures = $this->loginAttemptRepository->countRecentFailuresByIp(
            ipAddress: $ipAddress,
            minutes: self::WINDOW_MINUTES
        );

        return $ipFailures >= self::IP_CAPTCHA_LIMIT
            && $ipFailures < self::IP_BLOCK_LIMIT;
    }

    private function checkRateLimits(string $email, ?string $ipAddress, ?string $captchaToken): void
    {
        $emailFailures = $this->loginAttemptRepository->countRecentFailuresByEmail(
            email: $email,
            minutes: self::WINDOW_MINUTES
        );

        if ($emailFailures >= self::EMAIL_LOCK_LIMIT) {
            throw new HttpException(429, 'Account temporarily locked. Please try again later.');
        }

        if (! $ipAddress) {
            return;
        }

        $ipFailures = $this->loginAttemptRepository->countRecentFailuresByIp(
            ipAddress: $ipAddress,
            minutes: self::WINDOW_MINUTES
        );

        if ($ipFailures >= self::IP_BLOCK_LIMIT) {
            throw new HttpException(
                429,
                'This IP address has been temporarily blocked for ' . self::WINDOW_MINUTES . ' minutes due to too many failed login attempts.'
            );
        }

        if ($ipFailures >= self::IP_CAPTCHA_LIMIT) {
            if (empty($captchaToken)) {
                throw ValidationException::withMessages([
                    'cf-turnstile-response' => 'Please complete the security verification.',
                ]);
            }

            if (! $this->captchaVerifier->verify($captchaToken, $ipAddress)) {
                throw ValidationException::withMessages([
                    'cf-turnstile-response' => 'Security verification failed. Please try again.',
                ]);
            }
        }
    }

    private function recordFailedLogin(
        string $email,
        ?string $ipAddress,
        ?string $userAgent,
        int|string|null $clientAccountId = null
    ): void {
        $this->loginAttemptRepository->create([
            'email_lower' => $email,
            'ip_address' => $ipAddress,
            'outcome' => OperationOutcome::FAILURE->value,
            'attempted_at' => now(),
        ]);

        $this->authAuditService->log([
            'client_account_id' => $clientAccountId,
            'event_type' => AuditEventType::LOGIN_FAILURE->value,
            'outcome' => OperationOutcome::FAILURE->value,
            'failure_reason' => $clientAccountId ? 'wrong_password' : 'no_such_account',
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        $failureCount = $this->loginAttemptRepository->countRecentFailuresByEmail(
            email: $email,
            minutes: self::WINDOW_MINUTES
        );

        if ($clientAccountId && $failureCount === self::EMAIL_LOCK_LIMIT) {
            $this->authAuditService->log([
                'client_account_id' => $clientAccountId,
                'event_type' => AuditEventType::LOGIN_LOCKOUT->value,
                'outcome' => OperationOutcome::FAILURE->value,
                'failure_reason' => 'locked',
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);

            SendAccountLockedEmailJob::dispatch(
                email: $email,
                ipAddress: $ipAddress ?? 'Unknown',
                lockedAt: now()->toDateTimeString()
            )->afterCommit();
        }


        // Check lockout before Account Suspended.
        if ($clientAccountId) {

            $account = $this->clientAccountRepository
                ->find($clientAccountId);

            if ($account && ! $account->is_suspended) {

                $lockouts = $this->authAuditService
                    ->countEvents(
                        clientAccountId: $clientAccountId,
                        eventType: AuditEventType::LOGIN_LOCKOUT,
                        hours: self::LOCKOUT_WINDOW_HOURS
                    );


                if ($lockouts >= self::MAX_LOCKOUTS_BEFORE_SUSPEND) {

                    $suspendedAt = now();

                    $this->clientAccountRepository->update(
                        $account,
                        [
                            'is_suspended' => true,
                            'suspended_reason' => 'multiple_lockouts',
                            'suspended_at' => $suspendedAt,
                        ]
                    );

                    $this->authAuditService->log([
                        'client_account_id' => $clientAccountId,
                        'event_type' => AuditEventType::ACCOUNT_SUSPENDED->value,
                        'outcome' => OperationOutcome::SUCCESS->value,
                        'ip_address' => $ipAddress,
                        'user_agent' => $userAgent,
                        'metadata' => [
                            'reason' => 'multiple_lockouts',
                            'lockouts' => $lockouts,
                            'window_hours' => self::LOCKOUT_WINDOW_HOURS,
                            'suspended_at' => $suspendedAt->toISOString(),
                        ],
                    ]);

                    SendAccountSuspendedEmailJob::dispatch(
                        email: $account->email,
                        clientName: $account->owner?->client_name ?? 'Merchant',
                        suspendedAt: $suspendedAt->toDateTimeString(),
                    )->afterCommit();
                }
            }
        }
    }

    private function handleNewDeviceLogin(
        mixed $account,
        int|string $sessionId,
        ?string $ipAddress,
        ?string $userAgent
    ): void {
        $location = $this->geoIpService->resolve();

        $this->authAuditService->log([
            'client_account_id' => $account->id,
            'event_type' => AuditEventType::LOGIN_NEW_DEVICE->value,
            'outcome' => OperationOutcome::SUCCESS->value,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'metadata' => [
                'session_id' => $sessionId,
                'location' => $location,
            ],
        ]);

        SendNewDeviceLoginEmailJob::dispatch(
            $account->email,
            $ipAddress ?? 'Unknown',
            $location,
            now()->toDateTimeString()
        )->afterCommit();
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function makeDeviceFingerprint(?string $ipAddress, ?string $userAgent): string
    {
        return hash('sha256', trim((string) $ipAddress) . '|' . trim((string) $userAgent));
    }

    public function logout(
        string $rawSessionToken,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $requestId = null
    ): void {
        $tokenHash = hash('sha256', $rawSessionToken);

        $session = $this->merchantSessionRepository->findActiveByTokenHash($tokenHash);

        if (! $session) {
            return;
        }

        $session->update([
            'revoked_at' => now(),
            'revoked_reason' => 'logout',
        ]);

        $this->authAuditService->log([
            'event_type' => AuditEventType::LOGOUT->value,
            'client_account_id' => $session->client_account_id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'request_id' => $requestId,
            'outcome' => OperationOutcome::SUCCESS->value,
            'failed_reason' => null,
            'metadata' => [
                'session_id' => $session->id,
            ],
        ]);
    }
}
