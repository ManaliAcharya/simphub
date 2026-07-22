<?php

namespace Modules\Boarding\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Boarding\Models\BoardingMerchant;
use RuntimeException;

class DispatchBoardingWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 7;

    public function __construct(
        public readonly string $boardingMerchantId,
        public readonly string $event,
    ) {}

    /**
     * Retry delays in seconds: 30s, 2m, 10m, 1h, 6h, 24h.
     */
    public function backoff(): array
    {
        return [30, 120, 600, 3600, 21600, 86400];
    }

    public function handle(): void
    {
        $merchant = BoardingMerchant::find($this->boardingMerchantId);

        if (! $merchant) {
            return;
        }

        $client = $merchant->client;

        if (empty($client?->webhook_url)) {
            return;
        }

        $secret    = $client->webhook_secret;
        $body      = $this->buildPayload($merchant);
        $json      = (string) json_encode($body);
        $timestamp = time();
        $signature = $this->sign($secret, $timestamp, $json);

        $response = Http::withHeaders([
            'Content-Type'         => 'application/json',
            'Middleware-Signature' => "t={$timestamp},v1={$signature}",
        ])->post($client->webhook_url, $body);

        if (! $response->successful()) {
            Log::warning('Boarding webhook delivery failed', [
                'boarding_merchant_id' => $this->boardingMerchantId,
                'event'                => $this->event,
                'status'               => $response->status(),
                'attempt'              => $this->attempts(),
            ]);

            throw new RuntimeException("Boarding webhook delivery failed with HTTP {$response->status()}.");
        }
    }

    private function buildPayload(BoardingMerchant $merchant): array
    {
        return [
            'id'         => 'evt_' . Str::uuid(),
            'event'      => $this->event,
            'created_at' => now()->toIso8601String(),
            'data'       => [
                'merchant_ref' => $merchant->merchant_ref,
                'agent_ref'    => $merchant->agent_ref,
                'processor'    => $merchant->processor,
                'tier'         => $merchant->tier,
                'status'       => $merchant->status,
                'clicked_at'   => $merchant->clicked_at?->toIso8601String(),
            ],
        ];
    }

    private function sign(?string $secret, int $timestamp, string $json): string
    {
        if (! $secret) {
            return '';
        }

        return hash_hmac('sha256', "{$timestamp}.{$json}", $secret);
    }
}
