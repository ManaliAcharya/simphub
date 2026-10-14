<?php

namespace Modules\Inbound\Services\Connectors;

use Modules\Inbound\Contracts\PmsConnectorInterface;
use Modules\Inbound\DTOs\PmsCallbackResult;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Services\SubscriptionHistoryService;
use Modules\Inbound\Services\ZohoCrmApiClient;
use Modules\Inbound\Services\ZohoCrmOAuthService;
use Modules\Inbound\Services\ZohoCrmPaymentSyncService;
use Throwable;

/**
 * Zoho CRM clients call our subscription API instead of sending invoice webhooks.
 * Connecting is optional: once connected, each subscription payment is written
 * back to the customer's Contact (see ZohoCrmPaymentSyncService).
 */
class ZohoCrmConnector implements PmsConnectorInterface
{
    public const PROVIDER = ZohoCrmOAuthService::PROVIDER;

    public function __construct(
        private readonly SubscriptionHistoryService $history,
        private readonly ZohoCrmOAuthService $oauth,
        private readonly ZohoCrmApiClient $api,
        private readonly ZohoCrmPaymentSyncService $paymentSync,
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
        return $this->oauth->authorizationUrl($pmsClientId);
    }

    public function completeAuthorization(string $code, string $pmsClientId): PmsCallbackResult
    {
        $connection = $this->oauth->exchangeCode($code, $pmsClientId);

        try {
            $organization = $this->api->fetchOrganization($connection);

            $connection->forceFill(['meta' => [
                ...((array) $connection->meta),
                'organization_id' => $organization['id'] ?? null,
                'organization_name' => $organization['company_name'] ?? null,
            ]])->save();
        } catch (Throwable $e) {
            logger()->warning('Zoho CRM: could not fetch organization', [
                'pms_client_id' => $pmsClientId,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->paymentSync->ensureContactFields($connection->fresh(), recheck: true);
            $message = 'Zoho CRM connected. Payment fields were added to Contacts.';
        } catch (Throwable $e) {
            logger()->error('Zoho CRM: contact field setup failed', [
                'pms_client_id' => $pmsClientId,
                'error' => $e->getMessage(),
            ]);
            $message = 'Zoho CRM connected, but the payment fields could not be added to Contacts automatically. See the instructions below.';
        }

        return new PmsCallbackResult(
            connection: $connection->fresh(),
            successMessage: $message,
        );
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
            'organization_name'    => data_get($connection?->meta, 'organization_name'),
            'organization_id'      => data_get($connection?->meta, 'organization_id'),
            'crmFieldsSetup'       => data_get($connection?->meta, 'contact_fields_setup'),
            'crmFieldsSetupError'  => data_get($connection?->meta, 'contact_fields_setup_error'),
            'crmFields'            => collect(ZohoCrmPaymentSyncService::FIELDS)
                ->map(fn (array $field, string $key) => [
                    'label'    => $field['field_label'],
                    'type'     => $field['data_type'],
                    'api_name' => data_get($connection?->meta, "contact_fields.{$key}"),
                ])
                ->values()
                ->all(),
            'webhook_instructions' => [],
            'baseUrl'              => rtrim(config('app.url'), '/'),
            'subscriptions'        => $this->history->paginate($client, request()->query('q'), request()->query('status')),
            'subscriptionSummary'  => $this->history->summary($client),
            'subscriptionStatuses' => $this->history->statuses(),
        ];
    }
}
