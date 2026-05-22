<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Modules\Inbound\Models\WaveConnection;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class WaveWebhookService
{
    public function __construct(
        private readonly InternalInboundApiCaller $inboundApi,
    ) {}

    public function handleIncoming(Request $request): Response
    {
        $webhookSecret = config('services.wave.webhook_secret', '');

        if ($webhookSecret !== '') {
            $signature = (string) $request->header('X-Wave-Webhook-Signature', '');
            $payload   = $request->getContent();

            $normalizedSignature = str_starts_with($signature, 'sha256=')
                ? substr($signature, 7)
                : $signature;

            $expected = hash_hmac('sha256', $payload, $webhookSecret);

            if ($normalizedSignature !== '' && ! hash_equals($expected, $normalizedSignature)) {
                return response()->json(['message' => 'Invalid Wave webhook signature.'], 401);
            }
        }

        $payloadData = $request->json()->all();

        $invoiceId = (string) (
            Arr::get($payloadData, 'data.invoice_id')
            ?? Arr::get($payloadData, 'data.invoiceId')
            ?? Arr::get($payloadData, 'data.id')
            ?? Arr::get($payloadData, 'id')
            ?? ''
        );

        if ($invoiceId === '') {
            return response()->json(['message' => 'Webhook payload does not contain an invoice id.'], 422);
        }

        $pmsClientId = (string) $request->query('pms_client_id', '');

        if ($pmsClientId === '') {
            $pmsClientId = $this->resolvePmsClientIdFromPayload($payloadData);
        }

        if ($pmsClientId === '') {
            return response()->json(['message' => 'Cannot resolve PMS client identifier from Wave webhook.'], 422);
        }

        $this->inboundApi->callInvoiceIngestion('wave', array_merge($payloadData, [
            'invoice_id'     => $invoiceId,
            'pms_client_id'  => $pmsClientId,
            'event_name'     => (string) (Arr::get($payloadData, 'event_type') ?? Arr::get($payloadData, 'topic') ?? 'invoice.approved'),
            'headers'        => $request->headers->all(),
        ]));

        return response()->json(['accepted' => true], 202);
    }

    private function resolvePmsClientIdFromPayload(array $payload): string
    {
        $businessId = (string) (
            Arr::get($payload, 'business_id')
            ?? Arr::get($payload, 'data.businessId')
            ?? Arr::get($payload, 'businessId')
            ?? ''
        );

        logger()->info('Wave webhook: resolving client', [
            'business_id_from_payload' => $businessId,
            'all_wave_connections_meta' => WaveConnection::where('provider', 'wave')->pluck('meta', 'pms_client_id'),
        ]);

        if ($businessId === '') {
            return '';
        }

        // Try plain UUID first, then fall back to legacy base64 global ID format
        $base64Id   = base64_encode('Business:' . $businessId);
        $connection = WaveConnection::query()
            ->where('provider', 'wave')
            ->where(function ($q) use ($businessId, $base64Id): void {
                $q->whereJsonContains('meta->business_id', $businessId)
                  ->orWhereJsonContains('meta->business_id', $base64Id);
            })
            ->first();

        logger()->info('Wave webhook: lookup result', [
            'found_pms_client_id' => $connection?->pms_client_id,
        ]);

        return (string) ($connection?->pms_client_id ?? '');
    }
}
