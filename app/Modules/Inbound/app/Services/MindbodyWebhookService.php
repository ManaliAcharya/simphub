<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Inbound\Models\MindbodySite;
use Modules\Inbound\Models\MindbodySaleLink;
use Symfony\Component\HttpFoundation\Response;

class MindbodyWebhookService
{
    public function __construct(
        private readonly MindbodyAuthService $auth,
        private readonly MindbodyApiClient $api,
        private readonly MindbodySaleIngestionService $ingestion,
    ) {}

    public function handleIncoming(Request $request, string $siteId): Response
    {
        $site = MindbodySite::query()
            ->where('site_id', $siteId)
            ->where('is_active', true)
            ->first();

        if (! $site) {
            Log::warning('Mindbody webhook: no active site found', ['site_id' => $siteId]);
            return response()->json(['accepted' => true], 202);
        }

        // Verify HMAC-SHA256 signature
        if (! $this->verifySignature($request, $site)) {
            Log::error('Mindbody webhook: invalid signature', ['site_id' => $siteId]);
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $eventId   = (string) ($request->header('X-Mindbody-EventId', ''));
        $eventType = (string) $request->json('eventId', '');
        $payload   = (array)  $request->json()->all();

        Log::debug('Mindbody webhook received', [
            'site_id'    => $siteId,
            'event_type' => $eventType,
            'event_id'   => $eventId,
        ]);

        try {
            match ($eventType) {
                'clientSale.created'           => $this->handleClientSaleCreated($payload, $site),
                'appointmentBooking.cancelled' => $this->handleBookingCancelled($payload, $site),
                default                        => null,
            };
        } catch (\Throwable $e) {
            Log::error('Mindbody webhook: handler failed', [
                'site_id'    => $siteId,
                'event_type' => $eventType,
                'error'      => $e->getMessage(),
            ]);
        }

        return response()->json(['accepted' => true], 202);
    }

    private function handleClientSaleCreated(array $payload, MindbodySite $site): void
    {
        $data   = $payload['eventData'] ?? $payload;
        $saleId = (string) ($data['SaleId'] ?? $data['saleId'] ?? '');

        if ($saleId === '') {
            Log::warning('Mindbody clientSale.created: missing SaleId', ['payload' => $payload]);
            return;
        }

        // Idempotency — skip if already processed
        $existing = MindbodySaleLink::query()
            ->where('mindbody_site_id', $site->id)
            ->where('sale_id', $saleId)
            ->whereIn('payment_status', ['pending', 'sent', 'paid'])
            ->first();

        if ($existing) {
            Log::debug('Mindbody clientSale.created: duplicate, skipping', ['sale_id' => $saleId]);
            return;
        }

        $site = $this->auth->ensureValidToken($site);

        $this->ingestion->processSale($site, $saleId, $payload);
    }

    private function handleBookingCancelled(array $payload, MindbodySite $site): void
    {
        $data   = $payload['eventData'] ?? $payload;
        $saleId = (string) ($data['SaleId'] ?? $data['saleId'] ?? '');

        if ($saleId === '') {
            return;
        }

        MindbodySaleLink::query()
            ->where('mindbody_site_id', $site->id)
            ->where('sale_id', $saleId)
            ->whereIn('payment_status', ['pending', 'sent'])
            ->update(['payment_status' => 'cancelled']);

        Log::info('Mindbody booking cancelled — payment link voided', [
            'site_id' => $site->site_id,
            'sale_id' => $saleId,
        ]);
    }

    private function verifySignature(Request $request, MindbodySite $site): bool
    {
        $signatureKey = $site->signatureKey();

        if ($signatureKey === '') {
            // No key stored — allow during initial setup, log warning
            Log::warning('Mindbody webhook: no signature key stored, skipping verification', [
                'site_id' => $site->site_id,
            ]);
            return true;
        }

        $header   = (string) $request->header('X-Mindbody-Signature', '');
        $body     = $request->getContent();
        $computed = 'sha256=' . base64_encode(hash_hmac('sha256', $body, $signatureKey, true));

        return hash_equals($computed, $header);
    }
}
