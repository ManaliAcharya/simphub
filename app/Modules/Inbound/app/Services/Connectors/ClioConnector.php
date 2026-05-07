<?php

namespace Modules\Inbound\Services\Connectors;

use Modules\Inbound\Contracts\PmsConnectorInterface;
use Modules\Inbound\DTOs\PmsCallbackResult;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClioConnection;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Services\ClioApiClient;
use Modules\Inbound\Services\ClioOAuthService;
use Modules\Inbound\Services\ClioWebhookService;
use Throwable;

class ClioConnector implements PmsConnectorInterface
{
    public function __construct(
        private readonly ClioOAuthService $oauth,
        private readonly ClioWebhookService $webhooks,
        private readonly ClioApiClient $api,
    ) {}

    public function key(): string
    {
        return 'clio';
    }

    public function label(): string
    {
        return 'Clio';
    }

    public function authorizationUrl(string $pmsClientId): string
    {
        return $this->oauth->authorizationUrl($pmsClientId);
    }

    public function completeAuthorization(string $code, string $pmsClientId): PmsCallbackResult
    {
        $connection = $this->oauth->exchangeCode($code, $pmsClientId);
        $webhook = $this->webhooks->registerInvoiceCreatedWebhook($connection);

        return new PmsCallbackResult(
            connection: $connection,
            successMessage: 'Clio connected and webhook registered.',
            redirectParameters: [
                'webhook_id' => $webhook['id'] ?? null,
            ],
        );
    }

    public function webhookMode(): string
    {
        return 'automatic';
    }

    public function connection(?string $pmsClientId): ?PmsConnection
    {
        if (! $pmsClientId) {
            return null;
        }

        return PmsConnection::query()
            ->where('provider', 'clio')
            ->where('pms_client_id', $pmsClientId)
            ->latest('created_at')
            ->first();
    }

    public function integrationData(?Client $client, ?PmsConnection $connection): array
    {
        $bankAccounts = [];
        $bankAccountLoadError = null;

        if ($connection) {
            try {
                $clioConnection = ClioConnection::query()->find($connection->id);
                if ($clioConnection) {
                    $clioConnection = $this->oauth->ensureValidAccessToken($clioConnection);
                    $bankAccounts = $this->api->fetchBankAccounts($clioConnection);
                }
            } catch (Throwable $exception) {
                $bankAccountLoadError = $exception->getMessage();
            }
        }

        return [
            'heading' => 'Connect Clio for a configured client',
            'copy' => 'Create a client first, then connect that client to Clio so the generated PMS client id follows the webhook and invoice flow.',
            'webhook_url' => $connection?->webhook_url ?? config('services.clio.webhook_callback_url'),
            'webhook_instructions' => [],
            'clio_bank_accounts' => $bankAccounts,
            'clio_bank_account_load_error' => $bankAccountLoadError,
        ];
    }
}
