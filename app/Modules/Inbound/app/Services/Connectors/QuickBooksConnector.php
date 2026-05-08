<?php

namespace Modules\Inbound\Services\Connectors;

use Illuminate\Http\Request;
use Modules\Inbound\Contracts\PmsConnectorInterface;
use Modules\Inbound\DTOs\PmsCallbackResult;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Models\QuickBooksConnection;
use Modules\Inbound\Services\QuickBooksOAuthService;

class QuickBooksConnector implements PmsConnectorInterface
{
    public function __construct(
        private readonly QuickBooksOAuthService $oauth,
        private readonly Request $request,
    ) {}

    public function key(): string
    {
        return 'quickbooks';
    }

    public function label(): string
    {
        return 'QuickBooks';
    }

    public function authorizationUrl(string $pmsClientId): string
    {
        return $this->oauth->authorizationUrl($pmsClientId);
    }

    public function completeAuthorization(string $code, string $pmsClientId): PmsCallbackResult
    {
        $realmId    = (string) $this->request->query('realmId', '');
        $connection = $this->oauth->exchangeCode($code, $realmId, $pmsClientId);

        return new PmsCallbackResult(
            connection: $connection,
            successMessage: 'QuickBooks connected successfully.',
            redirectParameters: ['realm_id' => $realmId],
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

        return QuickBooksConnection::query()
            ->where('provider', 'quickbooks')
            ->where('pms_client_id', $pmsClientId)
            ->latest('created_at')
            ->first();
    }

    public function integrationData(?Client $client, ?PmsConnection $connection): array
    {
        $webhookUrl = rtrim((string) config('services.quickbooks.webhook_callback_url'), '/');
        $realmId    = $connection ? (string) data_get($connection->meta, 'realm_id', '') : '';

        return [
            'heading'     => 'Connect QuickBooks for a configured client',
            'copy'        => 'Authenticate with QuickBooks Online to enable invoice webhooks and payment sync.',
            'webhook_url' => $webhookUrl,
            'realm_id'    => $realmId,
            'webhook_instructions' => [
                'steps' => [
                    'Log in to the <strong>Intuit Developer Portal</strong> at developer.intuit.com and open your app.',
                    'Go to <strong>Production Keys</strong> (or Sandbox Keys) → <strong>Webhooks</strong>.',
                    'Click <strong>+ Add endpoint</strong> and paste the webhook URL shown below.',
                    'Under <strong>Entities</strong> select <strong>Invoice</strong> and check <strong>Create</strong> and <strong>Update</strong>.',
                    'Save — Intuit will display a <strong>Verifier Token</strong>. Copy it and set <code>QB_WEBHOOK_VERIFIER_TOKEN</code> in your <code>.env</code>.',
                ],
                'url' => $webhookUrl,
            ],
        ];
    }
}
