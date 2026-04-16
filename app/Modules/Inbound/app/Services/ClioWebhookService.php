<?php

namespace Modules\Inbound\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Modules\Inbound\Jobs\IngestInvoiceJob;
use Modules\Inbound\Models\ClioConnection;
use Modules\Inbound\DTOs\WebhookEvent;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ClioWebhookService
{
    public function __construct(
        private readonly ClioApiClient $client,
    ) {}

    public function registerInvoiceCreatedWebhook(ClioConnection $connection): array
    {
        $callbackUrl = $this->callbackUrl();
        $expiresAt = CarbonImmutable::now()->addDays(max(1, (int) config('services.clio.webhook_expiry_days', 30)));
        $payload = [
            'url' => $callbackUrl,
            'model' => config('services.clio.webhook_model', 'bill'),
            'events' => config('services.clio.webhook_events', ['created']),
            'fields' => config('services.clio.webhook_fields', []),
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        $response = $this->client->postWebhook($connection, ['data' => $payload]);

        if ($response->failed()) {
            $response = $this->client->postWebhook($connection, $payload);
        }
        $response->throw();

        $data = (array) $response->json('data', []);
        
        $connection->forceFill([
            'webhook_id' => Arr::get($data, 'id'),
            'webhook_url' => Arr::get($data, 'url', $callbackUrl),
            'webhook_expires_at' => Arr::get($data, 'expires_at', $expiresAt->toIso8601String()),
            'last_error' => null,
            'meta' => [
                'webhook' => $data,
            ],
        ])->save();

        return $data;
    }

    public function handleIncoming(Request $request): Response
    {
        $secret = $request->header('X-Hook-Secret');

        if (is_string($secret) && $secret !== '') {
            $connection = ClioConnection::query()->firstOrFail();
            $connection->forceFill([
                'webhook_secret' => $secret,
                'last_error' => null,
            ])->save();

            return response('', 200, ['X-Hook-Secret' => $secret]);
        }

        $connection = ClioConnection::query()->first();

        if (! $connection?->webhook_secret) {
            return response()->json(['message' => 'Clio webhook secret has not been initialized.'], 409);
        }

        $signature = (string) $request->header('X-Hook-Signature', '');
        $payload = $request->getContent();
        $normalizedSignature = str_contains($signature, '=')
            ? explode('=', $signature, 2)[1]
            : $signature;
        $expectedHex = hash_hmac('sha256', $payload, $connection->webhook_secret);
        $expectedBase64 = base64_encode(hash_hmac('sha256', $payload, $connection->webhook_secret, true));

        if (
            ! hash_equals($expectedHex, $normalizedSignature)
            && ! hash_equals($expectedBase64, $normalizedSignature)
        ) {
            return response()->json(['message' => 'Invalid Clio webhook signature.'], 401);
        }

        IngestInvoiceJob::dispatch(new WebhookEvent(
            source: 'clio',
            eventName: (string) Arr::get($request->json()->all(), 'data.event', 'created'),
            payload: $request->json()->all(),
            headers: $request->headers->all(),
        ));

        return response()->json(['accepted' => true], 202);
    }

    private function callbackUrl(): string
    {
        $url = config('services.clio.webhook_callback_url');

        if (! is_string($url) || trim($url) === '') {
            throw new RuntimeException('Missing Clio webhook callback URL.');
        }

        return $url;
    }
}
