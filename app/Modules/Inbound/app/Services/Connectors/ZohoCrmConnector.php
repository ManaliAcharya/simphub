<?php

namespace Modules\Inbound\Services\Connectors;

use Modules\Inbound\Contracts\PmsConnectorInterface;
use Modules\Inbound\DTOs\PmsCallbackResult;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Services\SubscriptionHistoryService;
use RuntimeException;

/**
 * Zoho CRM clients call our subscription API instead of sending invoice webhooks.
 * The Zoho CRM OAuth connection is not built yet, so this connector only feeds the
 * shared config page (API key, subscriptions); connecting is not available.
 */
class ZohoCrmConnector implements PmsConnectorInterface
{
    public const PROVIDER = 'zohocrm';

    public function __construct(
        private readonly SubscriptionHistoryService $history,
    ) {}

    public function key(): string
    {
        return self::PROVIDER;
    }

    public function label(): string
    {
        return 'Zoho CRM';
    }

    public function authorizationUrl(string $pmsClientId): string
    {
        throw new RuntimeException('Connecting Zoho CRM is not available yet.');
    }

    public function completeAuthorization(string $code, string $pmsClientId): PmsCallbackResult
    {
        throw new RuntimeException('Connecting Zoho CRM is not available yet.');
    }

    public function webhookMode(): string
    {
        return 'none';
    }

    public function connection(?string $pmsClientId): ?PmsConnection
    {
        if (! $pmsClientId) {
            return null;
        }

        return PmsConnection::query()
            ->where('provider', self::PROVIDER)
            ->where('pms_client_id', $pmsClientId)
            ->latest('created_at')
            ->first();
    }

    public function integrationData(?Client $client, ?PmsConnection $connection): array
    {
        if (! $client) {
            return [];
        }

        return [
            'webhook_instructions' => [],
            'baseUrl'              => rtrim(config('app.url'), '/'),
            'subscriptions'        => $this->history->paginate($client, request()->query('q'), request()->query('status')),
            'subscriptionSummary'  => $this->history->summary($client),
            'subscriptionStatuses' => $this->history->statuses(),
        ];
    }
}
