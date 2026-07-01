<?php

namespace Modules\Auth\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Enums\AuditEventType;
use Modules\Auth\Enums\OperationOutcome;
use Modules\Auth\Jobs\SendInvitationEmailJob;
use Modules\Auth\Repositories\InvitationRepository;

class InvitationService
{
    private const int TOKEN_EXPIRATION_DAYS = 7;

    public function __construct(
        private readonly ClientAccountService $clientAccountService,
        private readonly InvitationRepository $invitationRepository,
        private readonly MerchantSessionService $merchantSessionService,
        private readonly AuthAuditService $authAuditService,
    ) {}

    public function createInvitation(array $data): string
    {
        $email = strtolower(trim($data['email']));

        $rawToken = $this->generateToken();
        $url = $this->makeInvitationUrl($rawToken);

        $clientAccount = DB::transaction(function () use ($data, $email, $rawToken) {
            $clientAccount = $this->clientAccountService->createClientAccount([
                ...$data,
                'email' => $email,
            ]);

            $this->invitationRepository->createInvitationToken([
                'client_account_id' => $clientAccount->id,
                'token_hash' => $this->hashToken($rawToken),
                'expires_at' => now()->addDays(self::TOKEN_EXPIRATION_DAYS),
                'created_by_admin_id' => $data['admin_id'] ?? null,
            ]);

            return $clientAccount;
        });

        $this->sendInvitationEmail($email, $url);

        $this->authAuditService->log([
            'event_type' => AuditEventType::INVITATION_CREATED->value,
            'client_account_id' => $clientAccount->id,
            'email' => $email,
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'outcome' => OperationOutcome::SUCCESS->value,
            'failed_reason' => null,
            'metadata' => [
                'message' => 'Admin created a new client account and the invitation email was queued for delivery.',
                'created_by_admin_id' => $data['admin_id'] ?? null,
                'expires_in_days' => self::TOKEN_EXPIRATION_DAYS,
            ],
        ]);

        return $url;
    }

    public function showInvitation(string $token): array
    {
        $invitation = $this->invitationRepository->findByRawToken($token);

        if (! $invitation || ! $invitation->clientAccount) {
            abort(404);
        }

        if ($invitation->consumed_at) {
            abort(404);
        }

        if ($invitation->expires_at->isPast()) {
            $this->authAuditService->log([
                'event_type' => AuditEventType::INVITATION_EXPIRED->value,
                'client_account_id' => $invitation->client_account_id,
                'email' => $invitation->clientAccount?->email,
                'outcome' => OperationOutcome::FAILURE->value,
                'failed_reason' => 'Invitation link expired.',
                'metadata' => [
                    'message' => 'Merchant clicked an expired invitation link.',
                    'expired_at' => $invitation->expires_at->toDateTimeString(),
                ],
            ]);

            abort(404);
        }

        return [
            'token'  => $token,
            'email'  => $invitation->clientAccount->email,
            'client' => $invitation->clientAccount->client,
        ];
    }

    public function acceptInvitation(array $data): string
    {
        return DB::transaction(function () use ($data) {
            $invitation = $this->invitationRepository->findValidByRawToken($data['token']);

            if (! $invitation || ! $invitation->clientAccount) {
                throw ValidationException::withMessages([
                    'token' => 'This invitation link is invalid or expired.',
                ]);
            }

            $account = $invitation->clientAccount;

            $this->clientAccountService->updatePasswordAndLoginInfo($account, [
                'password' => $data['password'],
                'ip_address' => $data['ip_address'] ?? null,
                'user_agent' => $data['user_agent'] ?? null,
            ]);

            $this->invitationRepository->consume($invitation);

            $rawSessionToken = $this->generateToken();

            $this->merchantSessionService->createMerchantSession([
                'client_account_id' => $account->id,
                'raw_session_token' => $rawSessionToken,
                'ip_address' => $data['ip_address'] ?? null,
                'user_agent' => $data['user_agent'] ?? null,
            ]);

            $this->authAuditService->log([
                'event_type' => AuditEventType::INVITATION_ACCEPTED->value,
                'client_account_id' => $account->id,
                'email' => $account->email,
                'ip_address' => $data['ip_address'] ?? null,
                'user_agent' => $data['user_agent'] ?? null,
                'outcome' => OperationOutcome::SUCCESS->value,
                'failed_reason' => null,
                'metadata' => [
                    'message' => 'Merchant accepted the invitation and successfully set the initial password.',
                ],
            ]);

            return $rawSessionToken;
        });
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function makeInvitationUrl(string $rawToken): string
    {
        return url('/invitation/accept/' . $rawToken);
    }

    private function sendInvitationEmail(string $email, string $url): void
    {
        SendInvitationEmailJob::dispatch(
            email: $email,
            url: $url
        );
    }

    public function sendInvitationForExistingAccount(array $data): ?string
    {
        $email = strtolower(trim($data['email']));
        $clientAccount = $this->clientAccountService->findByClientId($data['client_id']);

        if (!$clientAccount) {
            Log::warning('Client account not found while sending invitation.', [
                'client_id' => $data['client_id'],
                'email' => $email,
            ]);

            return null;
        }


        $rawToken = $this->generateToken();
        $url = $this->makeInvitationUrl($rawToken);

        $this->invitationRepository->createInvitationToken([
            'client_account_id' => $clientAccount->id,
            'token_hash' => $this->hashToken($rawToken),
            'expires_at' => now()->addDays(self::TOKEN_EXPIRATION_DAYS),
            'created_by_admin_id' => $data['admin_id'] ?? null,
        ]);

        $this->sendInvitationEmail($email,$url);

        $this->authAuditService->log([
            'event_type' => AuditEventType::INVITATION_CREATED->value,
            'client_account_id' => $clientAccount->id,
            'email' => $email,
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'outcome' => OperationOutcome::SUCCESS->value,
            'failed_reason' => null,
            'metadata' => [
                'message' => 'Invitation sent for existing client account.',
                'created_by_admin_id' => $data['admin_id'] ?? null,
                'expires_in_days' => self::TOKEN_EXPIRATION_DAYS,
            ],
        ]);


        return $url;
    }
}
