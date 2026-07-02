<?php

namespace Modules\Auth\Services;

use App\Support\Integrations\GeoIp\GeoIpService;
use Modules\Auth\Enums\AuditEventType;
use Modules\Auth\Enums\OperationOutcome;
use Modules\Auth\Models\MerchantSession;
use Modules\Auth\Repositories\MerchantSessionRepository;
use Illuminate\Database\Eloquent\Builder;
use Jenssegers\Agent\Agent;

class MerchantSessionService
{
    public const SESSION_DURATION_HOURS = 12;
    public const SESSION_IDLE_TIMEOUT = 30;
    private MerchantSessionRepository $merchantSessionRepository;
    private AuthAuditService $authAuditService;
    private Agent $agent;
    private GeoIpService $geoIpService;

    public function __construct(
        MerchantSessionRepository $merchantSessionRepository,
        AuthAuditService $authAuditService,
        GeoIpService $geoIpService,
        Agent $agent
    ) {
        $this->merchantSessionRepository = $merchantSessionRepository;
        $this->authAuditService = $authAuditService;
        $this->geoIpService = $geoIpService;
        $this->agent = $agent;
    }

    public function createMerchantSession(array $data): MerchantSession
    {
        $sessionData = $this->prepareSessionData($data);

        return $this->merchantSessionRepository->create($sessionData);
    }

    private function prepareSessionData(array $data): array
    {
        $now = now();

        $userAgent = $data['user_agent'] ?? null;
        $ipAddress = $data['ip_address'] ?? null;

        $browser = null;
        $platform = null;
        $location = null;

        if ($userAgent) {
            $this->agent->setUserAgent($userAgent);

            $browser = $this->agent->browser();
            $platform = $this->agent->platform();
        }

        if ($ipAddress) {
            try {
                $location = $this->geoIpService->resolve();
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return [
            'client_account_id'   => $data['client_account_id'],
            'session_token_hash'  => hash('sha256', $data['raw_session_token']),
            'last_activity_at'    => $now,
            'absolute_expires_at' => $now->copy()->addHours(self::SESSION_DURATION_HOURS),

            'ip_address'          => $ipAddress,
            'user_agent'          => $userAgent,
            'device_fingerprint'  => $data['device_fingerprint'] ?? null,

            'browser'             => $browser,
            'platform'            => $platform,
            'location'            => $location,
        ];
    }

    public function hasRecentDeviceFingerprint(string $clientAccountId, string $deviceFingerprint, int $days = 30): bool
    {
        return $this->merchantSessionRepository->hasRecentDeviceFingerprint(
            clientAccountId: $clientAccountId,
            deviceFingerprint: $deviceFingerprint,
            days: $days
        );
    }

    public function revokeAllForAccount(string $clientAccountId, string $reason = 'password_change'): int
    {
        return $this->merchantSessionRepository
            ->revokeAllForAccount(
                clientAccountId: $clientAccountId,
                reason: $reason
            );
    }

    public function resolveSession(
        string $rawSessionToken,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $requestId = null
    ): ?MerchantSession {
        $tokenHash = hash('sha256', $rawSessionToken);

        $session = $this->merchantSessionRepository->findActiveByTokenHash($tokenHash);
        if (! $session || ! $session->clientAccount) {
            return null;
        }

        $account = $session->clientAccount;

        if (! $account->is_active || $account->is_suspended) {
            return null;
        }

        // Cleanup stale sessions for this merchant
        $this->cleanupExpiredSessions(clientAccountId: $account->id, currentSessionId: $session->id);


        if ($session->last_activity_at->lt(now()->subMinutes(self::SESSION_IDLE_TIMEOUT))) {

            $this->merchantSessionRepository->update(
                $session,
                [
                    'revoked_at' => now(),
                    'revoked_reason' => 'idle_timeout',
                ]
            );

            $this->authAuditService->log([
                'event_type' => AuditEventType::SESSION_IDLE_EXPIRED->value,
                'client_account_id' => $account->id,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'request_id' => $requestId,
                'outcome' => OperationOutcome::FAILURE->value,
                'failed_reason' => 'idle_timeout',
                'metadata' => [
                    'session_id' => $session->id,
                ],
            ]);

            return null;
        }

        if ($session->absolute_expires_at->isPast()) {

            $this->merchantSessionRepository->update(
                $session,
                [
                    'revoked_at' => now(),
                    'revoked_reason' => 'absolute_timeout',
                ]
            );

            $this->authAuditService->log([
                'event_type' => AuditEventType::SESSION_ABSOLUTE_EXPIRED->value,
                'client_account_id' => $account->id,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'request_id' => $requestId,
                'outcome' => OperationOutcome::FAILURE->value,
                'failed_reason' => 'absolute_timeout',
                'metadata' => [
                    'session_id' => $session->id,
                ],
            ]);

            return null;
        }

        $this->merchantSessionRepository->update($session, [
            'last_activity_at' => now()
        ]);

        return $session;
    }

    public function touchLastActivity(MerchantSession $session)
    {
        return $this->merchantSessionRepository->update($session, [
            'last_activity_at' => now()
        ]);
    }

    public function markReauthenticated(MerchantSession $session)
    {
        return $this->merchantSessionRepository->update($session, [
            'reauthenticated_at' => now()
        ]);
    }

    public function hasValidReauthentication(MerchantSession $session): bool
    {

        if (!$session || !$session->reauthenticated_at) {
            return false;
        }

        return $session->reauthenticated_at->greaterThanOrEqualTo(
            now()->subMinutes(config('rate_limits.reauth.window_minutes', 5))
        );
    }

    /**
     * Get active sessions for a merchant account.
     */
    public function getActiveSessions(string $clientAccountId): Builder
    {
        return MerchantSession::query()
            ->where(
                'client_account_id',
                $clientAccountId
            )
            ->whereNull('revoked_at');
    }

    /**
     * Revoke a specific session.
     */
    public function revokeSession(
        MerchantSession $session,
        string $reason = 'other_session_revoke',
        ?array $context = []
    ): void {
        if ($session->revoked_at !== null) {
            return;
        }

        $now = now();

        $this->merchantSessionRepository->update(
            $session,
            [
                'revoked_at' => $now,
                'revoked_reason' => $reason,
            ]
        );

        $this->authAuditService->log([
            'event_type' => AuditEventType::SESSION_REVOKED_BY_USER->value,
            'client_account_id' => $session->client_account_id,
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'request_id' => $context['request_id'] ?? null,
            'outcome' => OperationOutcome::SUCCESS->value,
            'metadata' => [
                'session_id' => $session->id,
                'revoked_reason' => $reason,
                'revoked_at' => $now->toISOString(),
            ],
        ]);
    }

    /**
     * Revoke current session during logout.
     */
    public function revokeCurrentSession(MerchantSession $session): void
    {
        $this->merchantSessionRepository->update(
            $session,
            [
                'revoked_at' => now(),
                'revoked_reason' => 'logout',
            ]
        );
    }

    /**
     * Revoke all other active sessions except current.
     */
    public function revokeOtherSessions(
        string $clientAccountId,
        string $currentSessionId,
        ?array $context = []
    ): int {
        $revokedSessions = $this->merchantSessionRepository
            ->revokeOtherSessions(
                clientAccountId: $clientAccountId,
                currentSessionId: $currentSessionId
            );

        if ($revokedSessions === 0) {
            return 0;
        }

        $this->authAuditService->log([
            'event_type' => AuditEventType::SESSION_REVOKED_BY_USER->value,
            'client_account_id' => $clientAccountId,
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'request_id' => $context['request_id'] ?? null,
            'outcome' => OperationOutcome::SUCCESS->value,
            'metadata' => [
                'action' => 'revoke_other_sessions',
                'current_session_id' => $currentSessionId,
                'revoked_session_count' => $revokedSessions,
            ],
        ]);

        return $revokedSessions;
    }

    /**
     * Verify ownership of a session.
     */
    public function sessionBelongsToAccount(MerchantSession $session, string $clientAccountId): bool
    {
        return (string) $session->client_account_id === (string) $clientAccountId;
    }

    public function cleanupExpiredSessions(string $clientAccountId, string $currentSessionId): void
    {

        $this->merchantSessionRepository->revokeIdleSessions(
            clientAccountId: $clientAccountId,
            excludeSessionId: $currentSessionId,
            idleTimeoutMinutes: self::SESSION_IDLE_TIMEOUT
        );

        $this->merchantSessionRepository->revokeAbsoluteExpiredSessions(
            clientAccountId: $clientAccountId,
            excludeSessionId: $currentSessionId
        );
    }
}
