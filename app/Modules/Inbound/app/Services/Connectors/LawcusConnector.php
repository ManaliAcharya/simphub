<?php

namespace Modules\Inbound\Services\Connectors;

use Modules\Inbound\Contracts\PmsConnectorInterface;
use Modules\Inbound\DTOs\PmsCallbackResult;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\LawcusConnection;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Services\LawcusApiClient;
use Modules\Inbound\Services\LawcusOAuthService;
use Modules\Inbound\Services\LawcusWebhookService;
use Throwable;

class LawcusConnector implements PmsConnectorInterface
{
    public function __construct(
        private readonly LawcusOAuthService $oauth,
        private readonly LawcusWebhookService $webhooks,
        private readonly LawcusApiClient $api,
    ) {}

    public function key(): string
    {
        return 'lawcus';
    }

    public function label(): string
    {
        return 'Lawcus';
    }

    public function authorizationUrl(string $pmsClientId): string
    {
        return $this->oauth->authorizationUrl($pmsClientId);
    }

    public function completeAuthorization(string $code, string $pmsClientId): PmsCallbackResult
    {
        $connection = $this->oauth->exchangeCode($code, $pmsClientId);

        try {
            $webhook = $this->webhooks->registerInvoiceCreatedWebhook($connection);
        } catch (Throwable) {
            // Webhook registration failure is non-fatal — connection is still saved.
            $webhook = [];
        }

        return new PmsCallbackResult(
            connection: $connection,
            successMessage: 'Lawcus connected and webhook registered.',
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
            ->where('provider', 'lawcus')
            ->where('pms_client_id', $pmsClientId)
            ->latest('created_at')
            ->first();
    }

    public function integrationData(?Client $client, ?PmsConnection $connection): array
    {
        $bankAccounts        = [];
        $bankAccountLoadError = null;

        if ($connection) {
            try {
                $lawcusConnection = LawcusConnection::query()->find($connection->id);
                if ($lawcusConnection) {
                    $lawcusConnection = $this->oauth->ensureValidAccessToken($lawcusConnection);
                    $bankAccounts     = $this->api->fetchBankAccounts($lawcusConnection);
                }
            } catch (Throwable $exception) {
                $bankAccountLoadError = $exception->getMessage();
            }
        }

        return [
            'heading'                      => 'Connect Lawcus for a configured client',
            'copy'                         => 'Create a client first, then connect that client to Lawcus so invoice webhooks and payment sync work automatically.',
            'webhook_url'                  => $connection?->webhook_url ?? config('services.lawcus.webhook_callback_url'),
            'webhook_instructions'         => [],
            'lawcus_bank_accounts'         => $bankAccounts,
            'lawcus_bank_account_load_error' => $bankAccountLoadError,
        ];
    }
}
