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
            //$webhookUrl .= '?pms_client_id='.$client->pms_client_id;
        }
        $rawJson = '
{
    "pms_client_id": "'.$client->pms_client_id.'",
    "invoice_id": "${invoice.invoice_id}",
    "total_amount": "${invoice.total}",
    "status": "${invoice.status}",
    "source": "zoho"
}';

        $webhook_instructions = [
            'steps' => [
                'Log in to <strong>Zoho Books</strong>, click the <strong>Gear icon (Settings)</strong> in the top right corner, and select <strong>Automation</strong>.',
                'Select <strong>Workflow Actions</strong>, then click <strong>Webhooks</strong> and click the <strong>+ New Webhook</strong> button.',
                'Fill out the webhook details exactly as shown in the box below.',
                'To make the webhook trigger automatically, go to <strong>Settings > Automation > Workflow Rules</strong>.',
                'Click <strong>+ New Workflow Rule</strong> and define the criteria (e.g., <em>When an invoice is created</em>).',
                'Under the action section, choose <strong>Webhook</strong> and select the webhook you just created.'
            ],
            'url' => $webhookUrl,
            'json' => $rawJson,
            'demo_video_url' => config('services.zoho.webhook_demo_video_url'),
        ];
        return [
            'heading' => 'Connect Zoho for a configured client',
            'copy' => 'After Zoho authentication, follow below instructions to setup webhook into the client\'s Zoho organization so invoice-created notifications reach to the middleware.',
            'webhook_url' => $webhookUrl,
            'webhook_instructions' => $webhook_instructions,
            'organization_name' => data_get($connection?->meta, 'default_organization_name'),
            'organization_id' => data_get($connection?->meta, 'default_organization_id'),
        ];
    }
}
