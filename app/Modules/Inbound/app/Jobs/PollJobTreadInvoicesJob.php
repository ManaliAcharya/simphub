<?php

namespace Modules\Inbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Inbound\Models\JobTreadConnection;
use Modules\Inbound\Services\JobTreadSyncService;
use Throwable;

/**
 * The backstop for JobTread webhooks (unsigned, retries unknown): every few minutes, a full
 * catch-up pass for each connected client. See JobTreadSyncService.
 */
class PollJobTreadInvoicesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    /** A slow run must not overlap the next; expires so a killed worker can't block polling. */
    public int $uniqueFor = 900;

    public function handle(JobTreadSyncService $sync): void
    {
        $connections = JobTreadConnection::query()
            ->where('provider', JobTreadConnection::PROVIDER)
            ->orderByDesc('created_at')
            ->get()
            ->unique('pms_client_id')
            ->values();

        foreach ($connections as $connection) {
            try {
                $counts = $sync->sync($connection);

                if ($counts['emailed'] > 0 || $counts['failed'] > 0) {
                    Log::info('JobTread poll', ['pms_client_id' => $connection->pms_client_id] + $counts);
                }
            } catch (Throwable $e) {
                Log::error('JobTread poll failed', [
                    'pms_client_id' => $connection->pms_client_id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }
    }
}
