<?php

namespace Modules\Auth\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\Audit\Services\AuditLogger;
use Modules\Auth\Models\ClientAccount;
use Modules\Auth\Models\MerchantSession;

/**
 * Lets a super-admin open a read-only, time-limited view of a client's
 * portal/config screens without ever knowing or using the client's
 * password. Implemented as a specially-flagged MerchantSession so it reuses
 * every existing merchant.auth-gated screen (client config, gateways,
 * fees, boarding, etc.) instead of duplicating them; MerchantSessionMiddleware
 * blocks any non-GET request on that session, so nothing can be changed
 * while impersonating. Every start/end is written to the audit log.
 */
class ImpersonationService
{
    /**
     * Deliberately much shorter than a normal 12h merchant session
     * (MerchantSessionService::SESSION_DURATION_HOURS) — this is a
     * support look-in, not a working session.
     */
    public const SESSION_DURATION_MINUTES = 30;

    public function start(
        User $admin,
        ClientAccount $clientAccount,
        ?string $ipAddress,
        ?string $userAgent
    ): array {
        abort_if(
            ! $clientAccount->is_active || $clientAccount->is_suspended,
            422,
            'This client account is inactive or suspended and cannot be viewed.'
        );

        $rawToken = Str::random(64);

        $session = MerchantSession::create([
            'client_account_id' => $clientAccount->id,
            'session_token_hash' => hash('sha256', $rawToken),
            'last_activity_at' => now(),
            'absolute_expires_at' => now()->addMinutes(self::SESSION_DURATION_MINUTES),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'is_impersonation' => true,
            'impersonated_by_user_id' => $admin->id,
        ]);

        AuditLogger::log(
            eventType: 'IMPERSONATION_STARTED',
            entityType: 'client_account',
            entityId: $clientAccount->id,
            payload: [
                'admin_user_id' => $admin->id,
                'admin_email' => $admin->email,
                'ip_address' => $ipAddress,
                'merchant_session_id' => $session->id,
                'expires_at' => $session->absolute_expires_at->toIso8601String(),
            ],
            actorType: 'admin'
        );

        return [
            'session' => $session,
            'raw_session_token' => $rawToken,
        ];
    }

    public function end(MerchantSession $session, string $reason = 'admin_exited'): void
    {
        if ($session->revoked_at !== null) {
            return;
        }

        $session->update([
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ]);

        AuditLogger::log(
            eventType: 'IMPERSONATION_ENDED',
            entityType: 'client_account',
            entityId: $session->client_account_id,
            payload: [
                'admin_user_id' => $session->impersonated_by_user_id,
                'merchant_session_id' => $session->id,
                'reason' => $reason,
            ],
            actorType: 'admin'
        );
    }
}
