<?php

namespace Modules\Inbound\Services\Connectors;

use Modules\Inbound\Contracts\PmsConnectorInterface;
use Modules\Inbound\DTOs\PmsCallbackResult;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Services\ZohoOAuthService;

class ZohoConnector implements PmsConnectorInterface
{
    public function __construct(
        private readonly ZohoOAuthService $oauth,
    ) {}

    public function key(): string
    {
        return 'zoho';
    }

    public function label(): string
    {
        return 'Zoho';
    }

    public function authorizationUrl(string $pmsClientId): string
    {
        return $this->oauth->authorizationUrl($pmsClientId);
    }

    public function completeAuthorization(string $code, string $pmsClientId): PmsCallbackResult
    {
        $connection = $this->oauth->exchangeCode($code, $pmsClientId);

        return new PmsCallbackResult(
            connection: $connection,
            successMessage: 'Zoho connected. Set up the webhook in Zoho using the instructions below.',
        );
    }

    public function webhookMode(): string
    {
        return 'manual';
    }

    public function connection(?string $pmsClientId): ?PmsConnection
    {
        if (! $pmsClientId) {
            return null;
        }

        return PmsConnection::query()
            ->where('provider', 'zoho')
            ->where('pms_client_id', $pmsClientId)
            ->latest('created_at')
            ->first();
    }

    public function integrationData(?Client $client, ?PmsConnection $connection): array
    {
        $webhookUrl = rtrim((string) config('services.zoho.webhook_callback_url'), '/');
        if ($client?->pms_client_id) {
            $webhookUrl .= '?pms_client_id='.$client->pms_client_id;
        }

        return [
            'heading' => 'Connect Zoho for a configured client',
            'copy' => 'After Zoho authentication, copy the webhook URL below into the client\'s Zoho organization so bill-created notifications reach this middleware.',
            'webhook_url' => $webhookUrl,
            'webhook_instructions' => [
                'Go to Settings > Automation > Webhooks in the client\'s Zoho organization.',
                'Create a webhook for Bill > When a Bill is Created.',
                'Use POST as the HTTP method.',
                'Use the webhook URL shown on this page as the target endpoint.',
                'Include payload fields such as Bill ID, Vendor Name, and Amount so the middleware can identify the bill event.',
            ],
            'organization_name' => data_get($connection?->meta, 'default_organization_name'),
            'organization_id' => data_get($connection?->meta, 'default_organization_id'),
        ];
    }
}
