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
use RuntimeException;
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
        $existedBefore = ClioConnection::query()
            ->where('provider', 'clio')
            ->where('pms_client_id', $pmsClientId)
            ->exists();

        $connection = $this->oauth->exchangeCode($code, $pmsClientId);

        // Fetch and store the Clio firm's account_id immediately so we can detect the
        // same firm being connected to more than one client (mirrors WaveConnector's
        // business_id check).
        $accountId = $this->api->fetchAccountId($connection);

        $connectedToAnotherClient = ClioConnection::query()
            ->where('provider', 'clio')
            ->where('pms_client_id', '!=', $pmsClientId)
            ->whereJsonContains('meta->account_id', $accountId)
            ->exists();

        if ($connectedToAnotherClient) {
            // Only a brand-new connection is safe to delete outright — if this client already had
            // a (different) Clio connection before this attempt, leave the row as-is rather than
            // risk destroying prior state we didn't snapshot.
            if (! $existedBefore) {
                $connection->delete();
            }

            throw new RuntimeException(
                'This Clio account is already connected to a different client. Each Clio account can only be connected to one client — disconnect it there first, or connect a different Clio login.'
            );
        }

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
