<?php

namespace Modules\Auth\Repositories;

use App\Repositories\BaseRepository;
use Modules\Auth\Models\MerchantSession;

class MerchantSessionRepository extends BaseRepository
{
    public function __construct(MerchantSession $model)
    {
        parent::__construct($model);
    }

    /**
     * Create a merchant session.
     */
    public function create(array $data): MerchantSession
    {
        return $this->model->newQuery()->create($data);
    }

    /**
     * Check if a device fingerprint exists within the given period.
     */
    public function hasRecentDeviceFingerprint(
        string $clientAccountId,
        string $deviceFingerprint,
        int $days = 30
    ): bool {
        return $this->query()
            ->where('client_account_id', $clientAccountId)
            ->where('device_fingerprint', $deviceFingerprint)
            ->where('created_at', '>=', now()->subDays($days))
            ->exists();
    }

    /**
     * Find an active session by token hash.
     */
    public function findActiveByTokenHash(string $tokenHash): ?MerchantSession
    {
        return $this->model
            ->newQuery()
            ->with('clientAccount')
            ->where('session_token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * Revoke a single session.
     */
    public function revoke(
        MerchantSession $session,
        string $reason
    ): bool {
        return $session->update(
            $this->revocationPayload($reason)
        );
    }

    /**
     * Revoke all active sessions for an account.
     */
    public function revokeAllForAccount(
        string $clientAccountId,
        string $reason
    ): int {
        return $this->activeSessionsForAccount($clientAccountId)
            ->update(
                $this->revocationPayload($reason)
            );
    }

    /**
     * Revoke idle sessions except the current one.
     */
    public function revokeIdleSessions(
        string $clientAccountId,
        string $excludeSessionId,
        int $idleTimeoutMinutes
    ): int {
        return $this->activeSessionsForAccount($clientAccountId)
            ->where('id', '!=', $excludeSessionId)
            ->where(
                'last_activity_at',
                '<',
                now()->subMinutes($idleTimeoutMinutes)
            )
            ->update(
                $this->revocationPayload('idle_timeout')
            );
    }

    /**
     * Revoke sessions that exceeded absolute expiry.
     */
    public function revokeAbsoluteExpiredSessions(
        string $clientAccountId,
        string $excludeSessionId
    ): int {
        return $this->activeSessionsForAccount($clientAccountId)
            ->where('id', '!=', $excludeSessionId)
            ->where('absolute_expires_at', '<', now())
            ->update(
                $this->revocationPayload('absolute_timeout')
            );
    }

    /**
     * Revoke all other active sessions except the current one.
     */
    public function revokeOtherSessions(
        string $clientAccountId,
        string $currentSessionId
    ): int {
        return $this->activeSessionsForAccount($clientAccountId)
            ->where('id', '!=', $currentSessionId)
            ->update(
                $this->revocationPayload('other_session_revoke')
            );
    }

    /**
     * Base query for active sessions.
     */
    private function activeSessionsForAccount(string $clientAccountId)
    {
        return $this->query()
            ->where('client_account_id', $clientAccountId)
            ->whereNull('revoked_at');
    }

    /**
     * Common revocation payload.
     */
    private function revocationPayload(string $reason): array
    {
        return [
            'revoked_at' => now(),
            'revoked_reason' => $reason,
            'updated_at' => now(),
        ];
    }
}
