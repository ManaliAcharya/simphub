<?php

namespace Modules\Inbound\Services\Connectors;

use Modules\Inbound\Contracts\PmsConnectorInterface;
use Modules\Inbound\DTOs\PmsCallbackResult;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Models\WaveConnection;
use Modules\Inbound\Services\WaveApiClient;
use Modules\Inbound\Services\WaveOAuthService;
use RuntimeException;

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
        $existedBefore = WaveConnection::query()
            ->where('provider', 'wave')
            ->where('pms_client_id', $pmsClientId)
            ->exists();

        $connection = $this->oauth->exchangeCode($code, $pmsClientId);

        // Fetch and store business_id immediately so incoming webhooks can be routed to the right client
        $businessId = $this->api->fetchBusinessId($connection);

        $connectedToAnotherClient = WaveConnection::query()
            ->where('provider', 'wave')
            ->where('pms_client_id', '!=', $pmsClientId)
            ->whereJsonContains('meta->business_id', $businessId)
            ->exists();

        if ($connectedToAnotherClient) {
            // Only a brand-new connection is safe to delete outright — if this client already had
            // a (different) Wave connection before this attempt, leave the row as-is rather than
            // risk destroying prior state we didn't snapshot.
            if (! $existedBefore) {
                $connection->delete();
            }

            throw new RuntimeException(
                'This Wave account is already connected to a different client. Each Wave account can only be connected to one client — disconnect it there first, or connect a different Wave login.'
            );
        }

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

        return WaveConnection::query()
            ->where('provider', 'wave')
            ->where('pms_client_id', $pmsClientId)
            ->latest('created_at')
            ->first();
    }

    public function integrationData(?Client $client, ?PmsConnection $connection): array
    {
        $paymentAccounts    = [];
        $incomeAccounts     = [];
        $accountLoadError   = null;

        if ($connection) {
            try {
                $connection      = $this->oauth->ensureValidAccessToken($connection);
                $paymentAccounts = $this->api->fetchPaymentAccounts($connection);
                $incomeAccounts  = $this->api->fetchIncomeAccounts($connection);
            } catch (\Throwable $e) {
                $accountLoadError = $e->getMessage();
            }
        }

        return [
            'heading'                     => 'Connect Wave',
            'copy'                        => 'Authenticate with Wave to allow this middleware to fetch invoices and process payments on your behalf.',
            'webhook_instructions'        => [],
            'wave_payment_accounts'       => $paymentAccounts,
            'wave_income_accounts'        => $incomeAccounts,
            'wave_account_load_error'     => $accountLoadError,
            'wave_webhook_url'            => config('services.wave.webhook_callback_url', url('/api/v1/inbound/webhooks/wave')),
        ];
    }
}
