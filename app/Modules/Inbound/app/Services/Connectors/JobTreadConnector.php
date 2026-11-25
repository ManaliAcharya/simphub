<?php

namespace Modules\Inbound\Services\Connectors;

use Modules\Inbound\Contracts\PmsConnectorInterface;
use Modules\Inbound\DTOs\PmsCallbackResult;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\JobTreadConnection;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Services\JobTreadApiClient;
use Modules\Inbound\Services\JobTreadConnectionService;
use RuntimeException;

/**
 * JobTread has no OAuth: the merchant creates a grant key in JobTread and it's pasted on the
 * integration page (connectWithGrantKey).
 */
class JobTreadConnector implements PmsConnectorInterface
{
    public function __construct(
        private readonly JobTreadConnectionService $connections,
        private readonly JobTreadApiClient $api,
    ) {}

    public function key(): string
    {
        return JobTreadConnection::PROVIDER;
    }

    public function label(): string
    {
        return 'JobTread';
    }

    public function authorizationUrl(string $pmsClientId): string
    {
        throw new RuntimeException('JobTread connects with a grant key, not OAuth.');
    }

    public function completeAuthorization(string $code, string $pmsClientId): PmsCallbackResult
    {
        throw new RuntimeException('JobTread connects with a grant key, not OAuth.');
    }

    public function connectWithGrantKey(string $grantKey, string $pmsClientId, ?string $organizationId = null): PmsCallbackResult
    {
        $connection = $this->connections->connect($grantKey, $pmsClientId, $organizationId);
        $meta       = (array) ($connection->meta ?? []);
        $org        = trim(($meta['organization_name'] ?? '').' ['.($meta['organization_id'] ?? '').']');

        $message = "JobTread connected to {$org}.";

        if (! empty($meta['webhook_error'])) {
            $message .= ' The webhook could not be registered, so invoices are picked up by polling every 5 minutes.';
        }

        return new PmsCallbackResult(
            connection: $connection,
            successMessage: $message,
            redirectParameters: ['webhook_id' => $meta['webhook_id'] ?? null],
        );
    }

    public function webhookMode(): string
    {
        return 'automatic';
    }

    public function connection(?string $pmsClientId): ?PmsConnection
    {
        return JobTreadConnection::forClient($pmsClientId);
    }

    /** Used by the Invoice List's Send/Resend button. Null falls back to SimpHub's PDF. */
    public function fetchInvoicePdf(?PmsConnection $connection, string $documentId): ?string
    {
        $connection = $connection ? JobTreadConnection::query()->find($connection->id) : null;

        return $connection ? $this->api->fetchDocumentPdf($connection, $documentId) : null;
    }

    public function integrationData(?Client $client, ?PmsConnection $connection): array
    {
        $meta = (array) ($connection?->meta ?? []);

        return [
            'heading'                    => 'Connect JobTread for a configured client',
            'copy'                       => 'Create a client first, then paste a JobTread grant key so pending invoices are emailed and payments recorded back automatically.',
            'webhook_url'                => null,
            'webhook_instructions'       => [],
            'jobtread_organization_id'   => $meta['organization_id'] ?? null,
            'jobtread_organization_name' => $meta['organization_name'] ?? null,
            'jobtread_webhook_id'        => $meta['webhook_id'] ?? null,
            'jobtread_webhook_error'     => $meta['webhook_error'] ?? null,
            'jobtread_last_success_at'   => $meta['last_api_success_at'] ?? null,
            'jobtread_baseline_count'    => is_array($meta['baseline'] ?? null) ? count($meta['baseline']) : null,
        ];
    }
}
