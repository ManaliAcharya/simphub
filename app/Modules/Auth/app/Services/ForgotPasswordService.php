<?php

namespace Modules\Auth\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Enums\AuditEventType;
use Modules\Auth\Enums\OperationOutcome;
use Modules\Auth\Jobs\SendPasswordResetCodeNotificationJob;
use Modules\Auth\Jobs\SendPasswordResetCompletedEmailJob;
use Modules\Auth\Repositories\ClientAccountRepository;
use Modules\Auth\Repositories\PasswordResetTokenRepository;

class ForgotPasswordService
{
    private const int OTP_LIMIT_PER_HOUR = 3;
    private const int OTP_EXPIRY_MINUTES = 10;
    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly ClientAccountRepository $clientAccountRepository,
        private readonly PasswordResetTokenRepository $passwordResetTokenRepository,
        private readonly MerchantSessionService $merchantSessionService,
        private readonly AuthAuditService $authAuditService,
    ) {}

    public function requestCode(array $data): void
    {
        $email = strtolower(trim($data['email']));

        $account = $this->clientAccountRepository->findActiveByEmail($email);

        if (! $account) {
            $this->authAuditService->log([
                'event_type' => AuditEventType::OTP_REQUESTED_UNKNOWN_EMAIL->value,
                'email' => $email,
                'ip_address' => $data['ip_address'] ?? null,
                'user_agent' => $data['user_agent'] ?? null,
                'outcome' => OperationOutcome::FAILURE->value,
                'failed_reason' => 'email_not_found',
            ]);

            return;
        }

        $requestCount = $this->passwordResetTokenRepository
            ->countRequestsForEmailInLastHour($account->id);

        if ($requestCount >= self::OTP_LIMIT_PER_HOUR) {
            $this->authAuditService->log([
                'event_type' => AuditEventType::OTP_RATE_LIMITED->value,
                'client_account_id' => $account->id,
                'email' => $email,
                'ip_address' => $data['ip_address'] ?? null,
                'user_agent' => $data['user_agent'] ?? null,
                'outcome' => OperationOutcome::FAILURE->value,
                'failed_reason' => 'otp_rate_limited',
            ]);

            throw ValidationException::withMessages([
                'email' => 'You have exceeded the maximum number of password reset requests. Please try again after one hour.',
            ]);
        }

        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(self::OTP_EXPIRY_MINUTES);

        $this->passwordResetTokenRepository->create([
            'client_account_id' => $account->id,
            'code_hash' => hash('sha256', $code),
            'expires_at' => $expiresAt,
            'requested_from_ip' => $data['ip_address'] ?? '0.0.0.0',
        ]);

        $this->authAuditService->log([
            'event_type' => AuditEventType::OTP_REQUESTED->value,
            'client_account_id' => $account->id,
            'email' => $email,
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'outcome' => OperationOutcome::SUCCESS->value,
        ]);

        SendPasswordResetCodeNotificationJob::dispatch(
            email: $account->email,
            code: $code,
            expiresAt: $expiresAt
        );
    }

    public function verifyCode(array $data): void
    {
        $email = strtolower(trim($data['email']));

        $account = $this->clientAccountRepository->findActiveByEmail($email);

        if (! $account) {
            throw ValidationException::withMessages([
                'code' => 'Invalid or expired code.',
            ]);
        }

        $token = $this->passwordResetTokenRepository
            ->latestValidForAccount($account->id);

        if (! $token) {
            throw ValidationException::withMessages([
                'code' => 'Invalid or expired code.',
            ]);
        }

        $inputHash = hash('sha256', $data['code']);

        if (! hash_equals($token->code_hash, $inputHash)) {
            $token = $this->passwordResetTokenRepository->incrementAttempts($token);

            $this->authAuditService->log([
                'event_type' => AuditEventType::OTP_VERIFY_FAILURE->value,
                'client_account_id' => $account->id,
                'email' => $email,
                'ip_address' => $data['ip_address'] ?? null,
                'user_agent' => $data['user_agent'] ?? null,
                'outcome' => OperationOutcome::FAILURE->value,
                'failed_reason' => 'otp_invalid',
            ]);

            if ($token->attempts >= self::MAX_ATTEMPTS) {

                $this->authAuditService->log([
                    'event_type' => AuditEventType::OTP_ATTEMPTS_EXHAUSTED->value,
                    'client_account_id' => $account->id,
                    'email' => $email,
                    'ip_address' => $data['ip_address'] ?? null,
                    'user_agent' => $data['user_agent'] ?? null,
                    'request_id' => $data['request_id'] ?? null,
                    'outcome' => OperationOutcome::FAILURE->value,
                    'failed_reason' => 'otp_attempts_exhausted',
                    'metadata' => [
                        'attempts' => $token->attempts,
                        'max_attempts' => self::MAX_ATTEMPTS,
                    ],
                ]);

                $this->passwordResetTokenRepository->consume($token);

                throw ValidationException::withMessages([
                    'code' => 'Code expired — request a new one.',
                ]);
            }

            throw ValidationException::withMessages([
                'code' => 'Invalid code.',
            ]);
        }

        $this->authAuditService->log([
            'event_type' => AuditEventType::OTP_VERIFY_SUCCESS->value,
            'client_account_id' => $account->id,
            'email' => $email,
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'request_id' => $data['request_id'] ?? null,
            'outcome' => OperationOutcome::SUCCESS->value,
        ]);
    }

    public function resetPassword(array $data): void
    {
        DB::transaction(function () use ($data) {
            $email = strtolower(trim($data['email']));
            $ipAddress = $data['ip_address'] ?? null;
            $userAgent = $data['user_agent'] ?? null;

            $account = $this->clientAccountRepository->findActiveByEmail($email);

            if (! $account) {
                throw ValidationException::withMessages([
                    'code' => 'Invalid or expired verification code. Please request a new password reset code.',
                ]);
            }

            $token = $this->passwordResetTokenRepository
                ->latestValidForAccount($account->id);

            if (! $token) {
                throw ValidationException::withMessages([
                    'code' => 'Your verification code has expired or was already used. Please request a new code.',
                ]);
            }

            $inputCodeHash = hash('sha256', $data['code']);

            if (! hash_equals($token->code_hash, $inputCodeHash)) {
                throw ValidationException::withMessages([
                    'code' => 'Invalid verification code. Please check the code and try again.',
                ]);
            }

            $resetAt = now();

            $account->update([
                'password_hash' => Hash::make($data['password']),
            ]);

            $this->passwordResetTokenRepository->consume($token);

            $this->merchantSessionService->revokeAllForAccount(
                clientAccountId: $account->id,
                reason: 'password_change'
            );

            $this->authAuditService->log([
                'event_type' => AuditEventType::PASSWORD_CHANGED->value,
                'client_account_id' => $account->id,
                'email' => $email,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'outcome' => OperationOutcome::SUCCESS->value,
                'failed_reason' => null,
                'metadata' => [
                    'message' => 'Password reset completed successfully. All existing merchant sessions were revoked and the merchant must log in again.',
                    'reset_at' => $resetAt->toDateTimeString(),
                    'revoked_reason' => 'password_change',
                ],
            ]);

            SendPasswordResetCompletedEmailJob::dispatch(
                email: $account->email,
                time: $resetAt,
                ip: $ipAddress ?? 'unknown'
            )->afterCommit();
        });
    }
}
