<?php

namespace Modules\Inbound\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Modules\Inbound\Models\ClioConnection;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ClioWebhookService
{
    public function __construct(
        private readonly ClioApiClient $client,
        private readonly InternalInboundApiCaller $inboundApi,
        private readonly InvoiceLinkLifecycleService $lifecycle,
    ) {}

    public function registerInvoiceCreatedWebhook(ClioConnection $connection): array
    {
        $callbackUrl = $this->callbackUrl($connection);
        $expiresAt = CarbonImmutable::now()->addDays(max(1, (int) config('services.clio.webhook_expiry_days', 30)));
        $payload = [
            'url' => $callbackUrl,
            'model' => config('services.clio.webhook_model', 'bill'),
            'events' => config('services.clio.webhook_events', ['created']),
            'fields' => implode(',', config('services.clio.webhook_fields', [])),
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
            'webhook_url' => $callbackUrl,
            'webhook_expires_at' => Arr::get($data, 'expires_at', $expiresAt->toIso8601String()),
            'last_error' => null,
            'meta' => [
                'webhook' => $data,
            ],
        ])->save();

        return $data;
    }

    public const WEBHOOK_OK = 'ok';
    public const WEBHOOK_RENEWED = 'renewed';
    public const WEBHOOK_REGISTERED = 'registered';   // there was none on record
    public const WEBHOOK_MISSING = 'missing';         // Clio no longer had it (expired/deleted)
    public const WEBHOOK_SUSPENDED = 'suspended';     // Clio suspended it after failed deliveries

    /**
     * Daily health check that keeps bills — invoices and Trust Requests alike — arriving.
     * Asks Clio for the webhook's status: a suspended or missing webhook is replaced with a
     * new one (Clio documents no way to re-enable a suspended one), and a healthy one is
     * renewed once it expires before $renewBefore.
     *
     * @return array{action: string, webhook: array}
     */
    public function maintainWebhook(ClioConnection $connection, CarbonImmutable $renewBefore): array
    {
        $webhookId = trim((string) $connection->webhook_id);

        if ($webhookId === '') {
            return ['action' => self::WEBHOOK_REGISTERED, 'webhook' => $this->registerInvoiceCreatedWebhook($connection)];
        }

        $response = $this->client->fetchWebhook($connection, $webhookId);

        if ($response->status() === 404) {
            return ['action' => self::WEBHOOK_MISSING, 'webhook' => $this->registerInvoiceCreatedWebhook($connection)];
        }

        $response->throw();

        $current = (array) $response->json('data', []);

        if (strtolower((string) ($current['status'] ?? '')) === 'suspended') {
            // Best-effort: a leftover suspended webhook delivers nothing either way.
            $this->client->deleteWebhook($connection, $webhookId);

            return ['action' => self::WEBHOOK_SUSPENDED, 'webhook' => $this->registerInvoiceCreatedWebhook($connection)];
        }

        $expiresAt = $current['expires_at'] ?? $connection->webhook_expires_at;

        if (self::expiresBefore($expiresAt, $renewBefore)) {
            return ['action' => self::WEBHOOK_RENEWED, 'webhook' => $this->renewWebhook($connection)];
        }

        $connection->forceFill([
            'webhook_expires_at' => $expiresAt,
            'last_error' => null,
        ])->save();

        return ['action' => self::WEBHOOK_OK, 'webhook' => $current];
    }

    /** A webhook with no readable expiry counts as expiring — we can't tell it's still alive. */
    public static function expiresBefore(mixed $expiresAt, CarbonImmutable $renewBefore): bool
    {
        if ($expiresAt === null || $expiresAt === '') {
            return true;
        }

        try {
            return CarbonImmutable::parse($expiresAt)->lessThanOrEqualTo($renewBefore);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Pushes the webhook's expiry out again so invoices (and Trust Requests, which are bills)
     * keep arriving. If Clio no longer has the webhook — e.g. it already expired — a new one
     * is registered.
     */
    public function renewWebhook(ClioConnection $connection): array
    {
        $webhookId = trim((string) $connection->webhook_id);

        if ($webhookId === '') {
            return $this->registerInvoiceCreatedWebhook($connection);
        }

        $expiresAt = CarbonImmutable::now()->addDays(max(1, (int) config('services.clio.webhook_expiry_days', 30)));
        $response = $this->client->updateWebhookExpiry($connection, $webhookId, $expiresAt->toIso8601String());

        if ($response->status() === 404) {
            return $this->registerInvoiceCreatedWebhook($connection);
        }

        $response->throw();

        $data = (array) $response->json('data', []);

        $connection->forceFill([
            'webhook_expires_at' => Arr::get($data, 'expires_at', $expiresAt->toIso8601String()),
            'last_error' => null,
        ])->save();

        return $data;
    }

    public function handleIncoming(Request $request): Response
    {
        $pmsClientId = (string) $request->query('pms_client_id', '');
        $secret = $request->header('X-Hook-Secret');

        if (is_string($secret) && $secret !== '') {
            $connection = $this->findConnectionOrFail($pmsClientId);
            $connection->forceFill([
                'webhook_secret' => $secret,
                'last_error' => null,
            ])->save();

            return response('', 200, ['X-Hook-Secret' => $secret]);
        }

        $connection = $this->findConnection($pmsClientId);

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
        
        $payloadData = $request->json()->all();
        $invoiceId = (string) (
            Arr::get($payloadData, 'data.id')
            ?? Arr::get($payloadData, 'data.bill.id')
            ?? Arr::get($payloadData, 'id')
        );

        if ($invoiceId === '') {
            return response()->json(['message' => 'Webhook payload does not contain an invoice id.'], 422);
        }

        $rawEvent  = (string) Arr::get($payloadData, 'data.event', 'created');
        $operation = $this->normalizeOperation($rawEvent);

        if ($operation === 'delete') {
            $this->lifecycle->disableOnRemoval('clio', $invoiceId, $connection->pms_client_id, 'disabled');

            return response()->json(['accepted' => true], 202);
        }

        $this->inboundApi->callInvoiceIngestion('clio', array_merge($payloadData, [
            'invoice_id' => $invoiceId,
            'pms_client_id' => $connection->pms_client_id,
            'event_name' => $rawEvent,
            'operation' => $operation,
            'headers' => $request->headers->all(),
        ]));

        return response()->json(['accepted' => true], 202);
    }

    /**
     * Clio bill webhook events are 'created' | 'updated' | 'deleted'. Any
     * unrecognized value is treated as an update — never dropped or crashed on.
     */
    private function normalizeOperation(string $rawEvent): string
    {
        return match (strtolower($rawEvent)) {
            'created' => 'create',
            'deleted' => 'delete',
            default   => 'update',
        };
    }

    private function callbackUrl(ClioConnection $connection): string
    {
        $url = config('services.clio.webhook_callback_url');

        if (! is_string($url) || trim($url) === '') {
            throw new RuntimeException('Missing Clio webhook callback URL.');
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'pms_client_id='.$connection->pms_client_id;
    }

    private function findConnection(string $pmsClientId): ?ClioConnection
    {
        if ($pmsClientId === '') {
            return null;
        }

        return ClioConnection::query()
            ->where('provider', 'clio')
            ->where('pms_client_id', $pmsClientId)
            ->first();
    }

    private function findConnectionOrFail(string $pmsClientId): ClioConnection
    {
        $connection = $this->findConnection($pmsClientId);

        if (! $connection) {
            throw new RuntimeException('Unknown PMS client identifier for Clio webhook.');
        }

        return $connection;
    }
}
