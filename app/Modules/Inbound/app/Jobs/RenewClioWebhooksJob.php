<?php

namespace Modules\Inbound\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Inbound\Models\ClioConnection;
use Modules\Inbound\Services\ClioOAuthService;
use Modules\Inbound\Services\ClioWebhookService;
use Throwable;

/**
 * Clio webhooks expire (at most ~31 days), and once one does Clio stops sending bills —
 * invoices and Trust Requests alike — with no error on our side. Runs daily and renews
 * every live connection's webhook that expires within RENEW_WITHIN_DAYS.
 */
class RenewClioWebhooksJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    private const RENEW_WITHIN_DAYS = 7;

    public function handle(ClioOAuthService $oauth, ClioWebhookService $webhooks): void
    {
        $renewBefore = CarbonImmutable::now()->addDays(self::RENEW_WITHIN_DAYS);

        // Only the latest connection per client is live after a reconnect.
        $connections = ClioConnection::query()
            ->where('provider', 'clio')
            ->whereNotNull('pms_client_id')
            ->orderByDesc('created_at')
            ->get()
            ->unique('pms_client_id')
            ->filter(fn (ClioConnection $c) => self::needsRenewal($c->webhook_expires_at, $renewBefore))
            ->values();

        foreach ($connections as $connection) {
            try {
                $webhook = $webhooks->renewWebhook($oauth->ensureValidAccessToken($connection));

                Log::info('Clio webhook renewed', [
                    'pms_client_id' => $connection->pms_client_id,
                    'webhook_id'    => $webhook['id'] ?? $connection->webhook_id,
                    'expires_at'    => $webhook['expires_at'] ?? null,
                ]);
            } catch (Throwable $e) {
                // Left as-is so tomorrow's run retries it.
                $connection->forceFill(['last_error' => 'Webhook renewal failed: '.$e->getMessage()])->save();

                Log::error('Clio webhook renewal failed', [
                    'pms_client_id' => $connection->pms_client_id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }
    }

    /** A webhook with no recorded expiry is renewed too — we can't tell it's still alive. */
    public static function needsRenewal(mixed $expiresAt, CarbonImmutable $renewBefore): bool
    {
        if ($expiresAt === null || $expiresAt === '') {
            return true;
        }

        try {
            return CarbonImmutable::parse($expiresAt)->lessThanOrEqualTo($renewBefore);
        } catch (Throwable) {
            return true;
        }
    }
}
