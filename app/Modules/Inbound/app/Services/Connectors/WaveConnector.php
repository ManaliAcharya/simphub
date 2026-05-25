<?php

namespace Modules\Inbound\Services\Connectors;

use Modules\Inbound\Contracts\PmsConnectorInterface;
use Modules\Inbound\DTOs\PmsCallbackResult;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Services\WaveApiClient;
use Modules\Inbound\Services\WaveOAuthService;

class WaveConnector implements PmsConnectorInterface
{
    public function __construct(
        private readonly WaveOAuthService $oauth,
        private readonly WaveApiClient $api,
    ) {}

    public function key(): string
    {
        return 'wave';
    }

    public function label(): string
    {
        return 'Wave';
    }

    public function authorizationUrl(string $pmsClientId): string
    {
        return $this->oauth->authorizationUrl($pmsClientId);
    }

    public function completeAuthorization(string $code, string $pmsClientId): PmsCallbackResult
    {
        $connection = $this->oauth->exchangeCode($code, $pmsClientId);

        // Fetch and store business_id immediately so incoming webhooks can be routed to the right client
        $this->api->fetchBusinessId($connection);

        return new PmsCallbackResult(
            connection: $connection,
            successMessage: 'Wave connected successfully.',
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
            ->where('provider', 'wave')
            ->where('pms_client_id', $pmsClientId)
            ->latest('created_at')
            ->first();
    }

    public function integrationData(?Client $client, ?PmsConnection $connection): array
    {
        $paymentAccounts    = [];
        $accountLoadError   = null;

        if ($connection) {
            try {
                $connection      = $this->oauth->ensureValidAccessToken($connection);
                $paymentAccounts = $this->api->fetchPaymentAccounts($connection);
            } catch (\Throwable $e) {
                $accountLoadError = $e->getMessage();
            }
        }

        return [
            'heading'                     => 'Connect Wave',
            'copy'                        => 'Authenticate with Wave to allow this middleware to fetch invoices and process payments on your behalf.',
            'webhook_instructions'        => [],
            'wave_payment_accounts'       => $paymentAccounts,
            'wave_account_load_error'     => $accountLoadError,
        ];
    }
}
