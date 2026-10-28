<?php

namespace Modules\Payment\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Payment\Services\AchSettlementService;

/**
 * Daily: asks Paya whether each pending ACH payment has settled or been returned.
 * Paya's GETI setup can't send webhooks, so polling is the only way to find out.
 */
class PollAchSettlementsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function handle(AchSettlementService $settlements): void
    {
        $counts = $settlements->pollPending();

        Log::info('PollAchSettlementsJob: finished', $counts);
    }
}
