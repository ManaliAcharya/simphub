<?php

namespace Modules\Inbound\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Audit\Services\AuditLogger;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClioConnection;
use Modules\Inbound\Services\ClioOAuthService;
use Modules\Inbound\Services\ClioWebhookService;
use Throwable;

/**
 * Clio webhooks expire (at most ~31 days) and are suspended after repeated failed
 * deliveries; either way Clio stops sending bills — invoices and Trust Requests alike —
 * with no error on our side. Runs daily for every live connection: a suspended or missing
 * webhook is replaced and ops is emailed, and one that expires within RENEW_WITHIN_DAYS
 * is renewed.
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
            ->values();

        foreach ($connections as $connection) {
            $hadError = trim((string) $connection->last_error) !== '';

            try {
                $result  = $webhooks->maintainWebhook($oauth->ensureValidAccessToken($connection), $renewBefore);
                $webhook = $result['webhook'];

                Log::info('Clio webhook checked', [
                    'pms_client_id' => $connection->pms_client_id,
                    'action'        => $result['action'],
                    'webhook_id'    => $webhook['id'] ?? $connection->webhook_id,
                    'expires_at'    => $webhook['expires_at'] ?? null,
                ]);

                if (self::needsAlert($result['action'])) {
                    AuditLogger::log('CLIO_WEBHOOK_REPLACED', 'client', $connection->pms_client_id, [
                        'reason'     => $result['action'],
                        'webhook_id' => $webhook['id'] ?? null,
                    ]);

                    $this->alertOps($connection, self::replacedMessage($result['action']));
                }
            } catch (Throwable $e) {
                // Left as-is so tomorrow's run retries it.
                $connection->forceFill(['last_error' => 'Webhook renewal failed: '.$e->getMessage()])->save();

                Log::error('Clio webhook renewal failed', [
                    'pms_client_id' => $connection->pms_client_id,
                    'error'         => $e->getMessage(),
                ]);

                // Once per outage, not on every daily retry.
                if (! $hadError) {
                    $this->alertOps($connection, "SimpHub could not check or renew this firm's Clio webhook: {$e->getMessage()}\n\n"
                        .'Until this is fixed, Clio bills and Trust Requests may stop reaching SimpHub, so no payment-link emails go out. '
                        .'Tomorrow\'s run retries automatically; reconnecting Clio for this firm also fixes it.');
                }
            }
        }
    }

    /** Clio stopped (or never started) sending this firm's bills — someone should know. */
    public static function needsAlert(string $action): bool
    {
        return in_array($action, [
            ClioWebhookService::WEBHOOK_SUSPENDED,
            ClioWebhookService::WEBHOOK_MISSING,
            ClioWebhookService::WEBHOOK_REGISTERED,
        ], true);
    }

    /** A webhook with no recorded expiry is renewed too — we can't tell it's still alive. */
    public static function needsRenewal(mixed $expiresAt, CarbonImmutable $renewBefore): bool
    {
        return ClioWebhookService::expiresBefore($expiresAt, $renewBefore);
    }

    private static function replacedMessage(string $action): string
    {
        $why = match ($action) {
            ClioWebhookService::WEBHOOK_SUSPENDED => 'Clio had suspended this firm\'s webhook after repeated failed deliveries.',
            ClioWebhookService::WEBHOOK_MISSING   => 'Clio no longer had this firm\'s webhook (it expired or was deleted).',
            default                                => 'This firm had no Clio webhook on record.',
        };

        return $why."\n\nSimpHub registered a new webhook, so new bills and Trust Requests arrive again from now on. "
            .'Bills approved while the webhook was down did not reach SimpHub: check Clio for bills in Unpaid (awaiting payment) '
            .'without a SimpHub payment link and send those from the Invoice List.';
    }

    private function alertOps(ClioConnection $connection, string $message): void
    {
        $opsEmail = config('services.clio.ops_alert_email');

        if (! $opsEmail) {
            Log::warning('Clio webhook needs attention but CLIO_OPS_ALERT_EMAIL is not configured — no alert sent', [
                'pms_client_id' => $connection->pms_client_id,
                'message'       => $message,
            ]);

            return;
        }

        $client = Client::query()->where('pms_client_id', $connection->pms_client_id)->first();
        $name   = (string) ($client?->client_name ?? $client?->name ?? $connection->pms_client_id);

        try {
            Mail::raw("Firm: {$name} (PMS client {$connection->pms_client_id})\n\n{$message}", function ($mail) use ($opsEmail, $name) {
                $mail->to($opsEmail)->subject("Clio webhook needs attention: {$name}");
            });
        } catch (Throwable $e) {
            Log::error('Clio webhook alert email failed', [
                'pms_client_id' => $connection->pms_client_id,
                'error'         => $e->getMessage(),
            ]);
        }
    }
}
