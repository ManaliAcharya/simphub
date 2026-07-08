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
        AdvancedMdPractice::query()
            ->where('is_active', true)
            ->each(function (AdvancedMdPractice $practice) use ($sessionService, $api, $ingestion): void {
                $this->pollPractice($practice, $sessionService, $api, $ingestion);
            });
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

            $result = $api->listChargesSince($practice, $datechanged);

            foreach ($result['charges'] as $charge) {
                try {
                    $ingestion->ingest($practice, $charge);
                } catch (\Throwable $e) {
                    Log::error('AdvancedMD charge ingestion failed', [
                        'practice_id' => $practice->id,
                        'charge_id'   => $charge['charge_id'],
                        'error'       => $e->getMessage(),
                    ]);
                }
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
