<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Modules\Inbound\Models\LawcusConnection;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class LawcusWebhookService
{
    public function __construct(
        private readonly LawcusApiClient $client,
        private readonly InternalInboundApiCaller $inboundApi,
    ) {}

    public function registerInvoiceCreatedWebhook(LawcusConnection $connection): array
    {
        $callbackUrl = $this->callbackUrl($connection);

        $payload = [
            'url'    => $callbackUrl,
            'events' => config('services.lawcus.webhook_events', ['invoice.created']),
        ];

        $response = $this->client->postWebhook($connection, $payload);
        $response->throw();

        $data = (array) ($response->json('data') ?? $response->json());

        $connection->forceFill([
            'webhook_id'  => Arr::get($data, 'id'),
            'webhook_url' => $callbackUrl,
            'last_error'  => null,
            'meta'        => ['webhook' => $data],
        ])->save();

        return $data;
    }

    public function handleIncoming(Request $request): Response
    {
        $pmsClientId = (string) $request->query('pms_client_id', '');
        $secret      = $request->header('X-Hook-Secret');

        // Lawcus handshake: echo back X-Hook-Secret on first contact.
        if (is_string($secret) && $secret !== '') {
            $connection = $this->findConnectionOrFail($pmsClientId);
            $connection->forceFill([
                'webhook_secret' => $secret,
                'last_error'     => null,
            ])->save();

            return response('', 200, ['X-Hook-Secret' => $secret]);
        }

        $connection = $this->findConnection($pmsClientId);

        if (! $connection?->webhook_secret) {
            return response()->json(['message' => 'Lawcus webhook secret has not been initialized.'], 409);
        }

        // Signature verification (HMAC-SHA256, same as Clio).
        $signature          = (string) $request->header('X-Hook-Signature', '');
        $payload            = $request->getContent();
        $normalizedSignature = str_contains($signature, '=')
            ? explode('=', $signature, 2)[1]
            : $signature;
        $expectedHex    = hash_hmac('sha256', $payload, $connection->webhook_secret);
        $expectedBase64 = base64_encode(hash_hmac('sha256', $payload, $connection->webhook_secret, true));

        if (
            ! hash_equals($expectedHex, $normalizedSignature)
            && ! hash_equals($expectedBase64, $normalizedSignature)
        ) {
            return response()->json(['message' => 'Invalid Lawcus webhook signature.'], 401);
        }

        $payloadData = $request->json()->all();
        $invoiceId   = (string) (
            Arr::get($payloadData, 'data.id')
            ?? Arr::get($payloadData, 'data.invoice.id')
            ?? Arr::get($payloadData, 'id')
        );

        if ($invoiceId === '') {
            return response()->json(['message' => 'Webhook payload does not contain an invoice id.'], 422);
        }

        $this->inboundApi->callInvoiceIngestion('lawcus', array_merge($payloadData, [
            'invoice_id'  => $invoiceId,
            'pms_client_id' => $connection->pms_client_id,
            'event_name'  => (string) Arr::get($payloadData, 'event', 'invoice.created'),
            'headers'     => $request->headers->all(),
        ]));

        return response()->json(['accepted' => true], 202);
    }

    private function callbackUrl(LawcusConnection $connection): string
    {
        $url = config('services.lawcus.webhook_callback_url');

        if (! is_string($url) || trim($url) === '') {
            throw new RuntimeException('Missing Lawcus webhook callback URL.');
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'pms_client_id='.$connection->pms_client_id;
    }

    private function findConnection(string $pmsClientId): ?LawcusConnection
    {
        if ($pmsClientId === '') {
            return null;
        }

        return LawcusConnection::query()
            ->where('provider', 'lawcus')
            ->where('pms_client_id', $pmsClientId)
            ->first();
    }

    private function findConnectionOrFail(string $pmsClientId): LawcusConnection
    {
        $connection = $this->findConnection($pmsClientId);

        if (! $connection) {
            throw new RuntimeException('Unknown PMS client identifier for Lawcus webhook.');
        }

        return $connection;
    }
}
