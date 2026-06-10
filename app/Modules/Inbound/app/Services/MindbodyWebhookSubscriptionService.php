<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\MindbodySite;
use RuntimeException;

class MindbodyWebhookSubscriptionService
{
    private const WEBHOOK_BASE = 'https://mb-api.mindbodyonline.com/push/api/v1';

    private const EVENTS = [
        'clientSale.created',
        'appointmentBooking.cancelled',
        'client.created',
        'client.updated',
    ];

    public function __construct(
        private readonly MindbodyApiClient $apiClient,
    ) {}

    public function createAndActivate(MindbodySite $site): MindbodySite
    {
        // If already subscribed, skip
        if ($site->webhook_subscription_id && $site->webhook_active) {
            return $site;
        }

        $webhookUrl = rtrim(config('app.url'), '/') . "/api/v1/inbound/webhooks/mindbody/{$site->site_id}";

        $response = Http::withHeaders(['API-Key' => $this->apiKey()])
            ->acceptJson()
            ->asJson()
            ->post(self::WEBHOOK_BASE . '/subscriptions', [
                'eventIds'            => self::EVENTS,
                'eventSchemaVersion'  => 1,
                'webhookUrl'          => $webhookUrl,
                'referenceId'         => $site->id,
            ])
            ->throw()
            ->json();

        $subscriptionId = (string) ($response['subscriptionId'] ?? '');
        $signatureKey   = (string) ($response['messageSignatureKey'] ?? '');

        if ($subscriptionId === '') {
            throw new RuntimeException('Mindbody did not return a subscription ID.');
        }

        if ($signatureKey === '') {
            throw new RuntimeException('Mindbody did not return a signature key. Store it now — it is only returned once.');
        }

        // Activate the subscription
        Http::withHeaders(['API-Key' => $this->apiKey()])
            ->acceptJson()
            ->asJson()
            ->patch(self::WEBHOOK_BASE . "/subscriptions/{$subscriptionId}", ['Status' => 'Active'])
            ->throw();

        $site->forceFill([
            'webhook_subscription_id'         => $subscriptionId,
            'webhook_signature_key_encrypted' => $signatureKey,
            'webhook_active'                  => true,
        ])->save();

        return $site->fresh();
    }

    public function delete(MindbodySite $site): void
    {
        if (! $site->webhook_subscription_id) {
            return;
        }

        Http::withHeaders(['API-Key' => $this->apiKey()])
            ->delete(self::WEBHOOK_BASE . "/subscriptions/{$site->webhook_subscription_id}");

        $site->forceFill([
            'webhook_subscription_id'         => null,
            'webhook_signature_key_encrypted' => null,
            'webhook_active'                  => false,
        ])->save();
    }

    private function apiKey(): string
    {
        $key = (string) config('services.mindbody.api_key', '');

        if ($key === '') {
            throw new RuntimeException('MINDBODY_API_KEY is not configured.');
        }

        return $key;
    }
}
