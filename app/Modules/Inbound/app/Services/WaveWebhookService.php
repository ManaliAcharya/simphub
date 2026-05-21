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
            Arr::get($payloadData, 'data.invoiceId')
            ?? Arr::get($payloadData, 'data.payload.invoiceId')
            ?? Arr::get($payloadData, 'payload.invoiceId')
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
            'event_name'     => (string) (Arr::get($payloadData, 'topic') ?? Arr::get($payloadData, 'data.topic') ?? 'INVOICE_CREATED'),
            'headers'        => $request->headers->all(),
        ]));

        return response()->json(['accepted' => true], 202);
    }

    private function resolvePmsClientIdFromPayload(array $payload): string
    {
        $businessId = (string) (
            Arr::get($payload, 'data.businessId')
            ?? Arr::get($payload, 'ownerId')
            ?? Arr::get($payload, 'businessId')
            ?? ''
        );

        if ($businessId === '') {
            return '';
        }

        $connection = WaveConnection::query()
            ->where('provider', 'wave')
            ->whereJsonContains('meta->business_id', $businessId)
            ->first();

        return (string) ($connection?->pms_client_id ?? '');
    }
}
