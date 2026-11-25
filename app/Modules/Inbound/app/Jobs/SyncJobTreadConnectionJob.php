<?php

namespace Modules\Inbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Inbound\Models\JobTreadConnection;
use Modules\Inbound\Services\JobTreadSyncService;

/**
 * Runs a catch-up pass for one client shortly after a JobTread webhook. Dispatched with a
 * short delay and unique until it starts, so a burst of webhooks (one save fires several
 * events) becomes one pass; a webhook arriving while it runs queues the next one.
 */
class SyncJobTreadConnectionJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 120;

    /** @param list<string> $documentIds */
    public function __construct(
        public string $pmsClientId,
        public array $documentIds = [],
    ) {}

    public function uniqueId(): string
    {
        return $this->pmsClientId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(JobTreadSyncService $sync): void
    {
        $connection = JobTreadConnection::forClient($this->pmsClientId);

        if ($connection) {
            $sync->sync($connection, $this->documentIds);
        }
    }
}
