<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\PmsConnection;
use Throwable;

/**
 * Disconnects a client's Zoho Books ('zoho') or Zoho CRM ('zohocrm') connection.
 *
 * For Zoho Books it first removes what ZohoWebhookSetupService created in the
 * organization: the two workflow rules (removed first, since they use the webhooks)
 * and the two webhooks. Zoho CRM registers no webhooks. Then the refresh token is
 * revoked at Zoho and the connection deleted. A failed clean-up step is logged and
 * reported back, but never stops the disconnect.
 */
class ZohoDisconnectService
{
    /** Names ZohoWebhookSetupService gives what it creates. */
    public const WEBHOOK_NAMES = [
        'Payment Middleware – Invoice Notify',
        'Payment Middleware – Invoice Update Notify',
    ];

    public const WORKFLOW_NAMES = [
        'Payment Middleware – Invoice Created',
        'Payment Middleware – Invoice Updated',
    ];

    public function __construct(
        private readonly ZohoApiClient $api,
        private readonly ZohoOAuthService $booksOAuth,
        private readonly ZohoRegionResolver $regions,
    ) {}

    /**
     * @param  'zoho'|'zohocrm'  $provider
     * @return array{disconnected: bool, webhooks_removed: int, workflows_removed: int, cleanup_error: ?string, shared_organization: bool}
     */
    public function disconnect(string $provider, string $pmsClientId): array
    {
        $result = [
            'disconnected'        => false,
            'webhooks_removed'    => 0,
            'workflows_removed'   => 0,
            'cleanup_error'       => null,
            'shared_organization' => false,
        ];

        $connections = PmsConnection::query()
            ->where('provider', $provider)
            ->where('pms_client_id', $pmsClientId)
            ->get();

        foreach ($connections as $connection) {
            if ($provider === 'zoho') {
                $result = $this->removeBooksAutomation($connection, $result);
            }

            $this->revokeToken($connection);
            $connection->delete();
            $result['disconnected'] = true;
        }

        logger()->info('Zoho: disconnected', ['provider' => $provider, 'pms_client_id' => $pmsClientId, ...$result]);

        return $result;
    }

    private function removeBooksAutomation(PmsConnection $connection, array $result): array
    {
        $organizationId = (string) data_get($connection->meta, 'default_organization_id', '');

        if ($organizationId === '') {
            return $result;
        }

        // The webhooks and rules have fixed names, so a second SimpHub client connected to the
        // same Zoho organization relies on the very same ones. Leave them for that client.
        if ($this->organizationSharedWithAnotherClient($connection, $organizationId)) {
            $result['shared_organization'] = true;
            logger()->warning('Zoho disconnect: organization is used by another client, webhooks kept', [
                'pms_client_id'   => $connection->pms_client_id,
                'organization_id' => $organizationId,
            ]);

            return $result;
        }

        try {
            $connection = $this->booksOAuth->ensureValidAccessToken($connection);

            $workflowIds = $this->ourIds(
                (array) data_get($this->api->fetchWorkflows($connection, $organizationId), 'workflows', []),
                'workflow_name', 'workflow_id', self::WORKFLOW_NAMES,
                [data_get($connection->meta, 'workflow_id'), data_get($connection->meta, 'edit_workflow_id')],
            );

            foreach ($workflowIds as $id) {
                if ($this->api->deleteWorkflow($connection, $id, $organizationId)) {
                    $result['workflows_removed']++;
                }
            }

            $webhookIds = $this->ourIds(
                (array) data_get($this->api->fetchWebhooks($connection, $organizationId), 'webhooks', []),
                'webhook_name', 'webhook_id', self::WEBHOOK_NAMES,
                [data_get($connection->meta, 'webhook_id')],
            );

            foreach ($webhookIds as $id) {
                if ($this->api->deleteWebhook($connection, $id, $organizationId)) {
                    $result['webhooks_removed']++;
                }
            }
        } catch (Throwable $e) {
            $result['cleanup_error'] = $e->getMessage();
            logger()->warning('Zoho disconnect: could not remove webhooks/workflows', [
                'pms_client_id'   => $connection->pms_client_id,
                'organization_id' => $organizationId,
                'error'           => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * IDs to delete: those listed in Zoho under one of our names, plus the IDs saved on
     * the connection (in case a rule was renamed in Zoho). Saved IDs that Zoho no longer
     * lists are skipped, so nothing else in the organization is touched.
     *
     * @return list<string>
     */
    private function ourIds(array $items, string $nameKey, string $idKey, array $names, array $savedIds): array
    {
        $savedIds = array_filter(array_map('strval', $savedIds));

        return collect($items)
            ->filter(fn ($item) => in_array((string) data_get($item, $nameKey), $names, true)
                || in_array((string) data_get($item, $idKey), $savedIds, true))
            ->map(fn ($item) => (string) data_get($item, $idKey))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function organizationSharedWithAnotherClient(PmsConnection $connection, string $organizationId): bool
    {
        return PmsConnection::query()
            ->where('provider', 'zoho')
            ->where('pms_client_id', '!=', $connection->pms_client_id)
            ->get()
            ->contains(fn (PmsConnection $other) => (string) data_get($other->meta, 'default_organization_id') === $organizationId);
    }

    /** Revoking the refresh token also ends every access token issued from it. */
    private function revokeToken(PmsConnection $connection): void
    {
        if (! $connection->refresh_token) {
            return;
        }

        try {
            Http::asForm()
                ->acceptJson()
                ->timeout(15)
                ->post(rtrim($this->regions->accountsBaseUrlForConnection($connection), '/').'/oauth/v2/token/revoke?'.http_build_query([
                    'token' => $connection->refresh_token,
                ]))
                ->throw();
        } catch (Throwable $e) {
            logger()->warning('Zoho disconnect: token revoke failed', [
                'provider'      => $connection->provider,
                'pms_client_id' => $connection->pms_client_id,
                'error'         => $e->getMessage(),
            ]);
        }
    }
}
