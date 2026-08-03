<?php

namespace Modules\Inbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Inbound\Models\WaveConnection;
use Modules\Inbound\Services\InternalInboundApiCaller;
use Modules\Inbound\Services\WaveApiClient;
use Modules\Inbound\Services\WaveOAuthService;

/**
 * Wave has no reliable "invoice updated" webhook, so invoice edits are detected by
 * polling instead — mirrors PollAdvancedMdChargesJob's pattern for AdvancedMD, which
 * has the same limitation. Every changed invoice is routed through the same
 * WaveInvoiceIngestionService::ingest() path the (unreliable) webhook uses, so the
 * usual create/update handling — including resending the payment link when
 * auto_resend_on_change is enabled — applies identically.
 */
class PollWaveInvoicesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * After this many consecutive failures, stop polling a connection every 5 minutes
     * and wait for someone to reconnect it — avoids hammering a broken connection.
     */
    private const MAX_CONSECUTIVE_FAILURES = 5;

    public function handle(
        WaveOAuthService $oauth,
        WaveApiClient $api,
        InternalInboundApiCaller $inboundApi,
    ): void {
        // A client can have more than one Wave connection row after a reconnect —
        // only the latest per pms_client_id is live (same rule syncToWave() follows).
        $connections = WaveConnection::query()
            ->where('provider', 'wave')
            ->orderByDesc('created_at')
            ->get()
            ->unique('pms_client_id')
            ->values();

        Log::info('Wave invoice poll job started', ['connections' => $connections->count()]);

        $connections->each(function (WaveConnection $connection) use ($oauth, $api, $inboundApi): void {
            $this->pollConnection($connection, $oauth, $api, $inboundApi);
        });

        Log::info('Wave invoice poll job finished', ['connections' => $connections->count()]);
    }

    private function pollConnection(
        WaveConnection $connection,
        WaveOAuthService $oauth,
        WaveApiClient $api,
        InternalInboundApiCaller $inboundApi,
    ): void {
        $meta                 = (array) ($connection->meta ?? []);
        $consecutiveFailures  = (int) ($meta['invoice_poll_consecutive_failures'] ?? 0);

        if ($consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
            Log::warning('Wave invoice poll skipped — too many consecutive failures', [
                'pms_client_id'        => $connection->pms_client_id,
                'consecutive_failures' => $consecutiveFailures,
                'last_error'           => $meta['invoice_poll_last_error'] ?? null,
            ]);

            return;
        }

        // Non-overlapping window: next run starts exactly where this run's successful
        // fetch ended, so the same invoice is never re-detected (and re-emailed) twice.
        $windowStart = isset($meta['invoice_poll_window_start'])
            ? Carbon::parse($meta['invoice_poll_window_start'])
            : now()->subMinutes(10);
        $windowEnd = now();

        try {
            $connection = $oauth->ensureValidAccessToken($connection);

            $changed = $api->fetchInvoicesModifiedSince($connection, $windowStart);

            if (empty($changed)) {
                Log::info('Wave invoice poll found no changes', [
                    'pms_client_id' => $connection->pms_client_id,
                    'window_start'  => $windowStart->toIso8601String(),
                    'window_end'    => $windowEnd->toIso8601String(),
                ]);
            }

            $ingested = 0;
            $failed   = 0;

            foreach ($changed as $invoiceSummary) {
                try {
                    $inboundApi->callInvoiceIngestion('wave', [
                        'invoice_id'    => $invoiceSummary['id'],
                        'pms_client_id' => $connection->pms_client_id,
                        'event_name'    => 'poll.invoice.modified',
                        'operation'     => 'update',
                    ]);
                    $ingested++;
                } catch (\Throwable $e) {
                    $failed++;
                    Log::error('Wave invoice poll ingestion failed', [
                        'pms_client_id' => $connection->pms_client_id,
                        'invoice_id'    => $invoiceSummary['id'],
                        'error'         => $e->getMessage(),
                    ]);
                }
            }

            if (! empty($changed)) {
                Log::info('Wave invoice poll ingestion summary', [
                    'pms_client_id' => $connection->pms_client_id,
                    'found'         => count($changed),
                    'ingested'      => $ingested,
                    'failed'        => $failed,
                ]);
            }

            $connection->forceFill([
                'meta' => array_merge($meta, [
                    'invoice_poll_window_start'          => $windowEnd->toIso8601String(),
                    'invoice_poll_consecutive_failures'   => 0,
                    'invoice_poll_last_error'             => null,
                ]),
            ])->save();
        } catch (\Throwable $e) {
            $connection->forceFill([
                'meta' => array_merge($meta, [
                    'invoice_poll_consecutive_failures' => $consecutiveFailures + 1,
                    'invoice_poll_last_error'            => $e->getMessage(),
                ]),
            ])->save();

            Log::error('Wave invoice poll failed', [
                'pms_client_id'        => $connection->pms_client_id,
                'error'                => $e->getMessage(),
                'consecutive_failures' => $consecutiveFailures + 1,
            ]);
        }
    }
}
