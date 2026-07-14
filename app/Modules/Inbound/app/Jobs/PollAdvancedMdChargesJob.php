<?php

namespace Modules\Inbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Inbound\Models\AdvancedMdPractice;
use Modules\Inbound\Services\AdvancedMdApiClient;
use Modules\Inbound\Services\AdvancedMdChargeIngestionService;
use Modules\Inbound\Services\AdvancedMdSessionService;

class PollAdvancedMdChargesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function handle(
        AdvancedMdSessionService $sessionService,
        AdvancedMdApiClient $api,
        AdvancedMdChargeIngestionService $ingestion,
    ): void {
        $practiceCount = AdvancedMdPractice::query()->where('is_active', true)->count();

        Log::info('AdvancedMD poll job started', ['active_practices' => $practiceCount]);

        AdvancedMdPractice::query()
            ->where('is_active', true)
            ->each(function (AdvancedMdPractice $practice) use ($sessionService, $api, $ingestion): void {
                $this->pollPractice($practice, $sessionService, $api, $ingestion);
            });

        Log::info('AdvancedMD poll job finished', ['active_practices' => $practiceCount]);
    }

    private function pollPractice(
        AdvancedMdPractice $practice,
        AdvancedMdSessionService $sessionService,
        AdvancedMdApiClient $api,
        AdvancedMdChargeIngestionService $ingestion,
    ): void {
        try {
            $practice = $sessionService->ensureValidSession($practice);

            // Prefer AMD's own servertime from the last response; fall back to
            // last_polled_at formatted the same way AMD expects, or 10 min ago.
            $datechanged = $practice->last_sync_servertime
                ?? ($practice->last_polled_at?->format('m/d/Y g:i:s A'))
                ?? now()->subMinutes(10)->format('m/d/Y g:i:s A');

            Log::info('AdvancedMD polling practice', [
                'practice_id' => $practice->id,
                'office_key'  => $practice->office_key,
                'datechanged' => $datechanged,
            ]);

            $result = $api->listChargesSince($practice, $datechanged);

            if (empty($result['charges'])) {
                Log::info('AdvancedMD poll found no new charges', [
                    'practice_id' => $practice->id,
                    'datechanged' => $datechanged,
                    'servertime'  => $result['servertime'],
                ]);
            }

            $ingested = 0;
            $skipped  = 0;
            $failed   = 0;

            foreach ($result['charges'] as $charge) {
                try {
                    $outcome = $ingestion->ingest($practice, $charge);
                    if (! empty($outcome['skipped'])) {
                        $skipped++;
                    } else {
                        $ingested++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    Log::error('AdvancedMD charge ingestion failed', [
                        'practice_id' => $practice->id,
                        'charge_id'   => $charge['charge_id'],
                        'error'       => $e->getMessage(),
                    ]);
                }
            }

            if (! empty($result['charges'])) {
                Log::info('AdvancedMD poll ingestion summary', [
                    'practice_id' => $practice->id,
                    'found'       => count($result['charges']),
                    'ingested'    => $ingested,
                    'skipped'     => $skipped,
                    'failed'      => $failed,
                ]);
            }

            $practice->forceFill([
                'last_polled_at'      => now(),
                'last_sync_servertime'=> $result['servertime'],
                'last_error'          => null,
            ])->save();
        } catch (\Throwable $e) {
            $practice->forceFill(['last_error' => $e->getMessage()])->save();

            Log::error('AdvancedMD practice poll failed', [
                'practice_id' => $practice->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
