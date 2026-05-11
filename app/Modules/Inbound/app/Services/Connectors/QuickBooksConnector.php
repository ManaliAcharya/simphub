<?php

namespace Modules\Inbound\Services\Connectors;

use Illuminate\Http\Request;
use Modules\Inbound\Contracts\PmsConnectorInterface;
use Modules\Inbound\DTOs\PmsCallbackResult;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Models\QuickBooksConnection;
use Modules\Inbound\Services\QuickBooksApiClient;
use Modules\Inbound\Services\QuickBooksOAuthService;

class QuickBooksConnector implements PmsConnectorInterface
{
    public function __construct(
        private readonly QuickBooksOAuthService $oauth,
        private readonly QuickBooksApiClient $api,
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

        $qbAccounts          = [];
        $qbAccountLoadError  = null;

        if ($connection instanceof QuickBooksConnection && $client) {
            try {
                $freshConnection = $this->oauth->ensureValidAccessToken($connection);
                $qbAccounts      = $this->api->fetchChartOfAccounts($freshConnection);
            } catch (\Throwable $e) {
                $qbAccountLoadError = 'Could not load QuickBooks chart of accounts: '.$e->getMessage();
            }
        }

        return [
            'heading'              => 'Connect QuickBooks for a configured client',
            'copy'                 => 'Authenticate with QuickBooks Online to enable invoice webhooks and payment sync.',
            'webhook_url'          => $webhookUrl,
            'realm_id'             => $realmId,
            'qb_accounts'          => $qbAccounts,
            'qb_account_load_error' => $qbAccountLoadError,
            'webhook_instructions' => [],
        ];
    }
}
