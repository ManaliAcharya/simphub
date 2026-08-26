<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Services\ZohoOAuthService;
use Modules\Inbound\Services\ZohoWebhookSetupService;
use Throwable;

/**
 * Re-runs webhook/workflow auto-setup for an already-connected Zoho client,
 * without sending them through OAuth again. Safe to re-run any time — setup
 * upserts by name instead of always creating, so it won't hit Zoho's
 * duplicate-webhook-name error (code 107051) on a client that already has
 * one registered.
 */
class ZohoReregisterWebhookCommand extends Command
{
    protected $signature = 'zoho:reregister-webhook {pms_client_id}';

    protected $description = 'Re-run Zoho webhook/workflow auto-setup for an existing connection (no OAuth reconnect needed).';

    public function handle(ZohoOAuthService $oauth, ZohoWebhookSetupService $webhookSetup): int
    {
        $pmsClientId = (string) $this->argument('pms_client_id');

        $connection = PmsConnection::query()
            ->where('provider', 'zoho')
            ->where('pms_client_id', $pmsClientId)
            ->latest('created_at')
            ->first();

        if (! $connection) {
            $this->components->error("No Zoho connection found for pms_client_id [{$pmsClientId}]");

            return self::FAILURE;
        }

        $this->components->info('Ensuring access token is valid...');
        $connection = $oauth->ensureValidAccessToken($connection);

        $this->components->info('Running webhook/workflow auto-setup...');

        try {
            $ids = $webhookSetup->setup($connection, $pmsClientId);

            $connection->forceFill([
                'meta' => [
                    ...((array) $connection->meta),
                    'webhook_id' => $ids['webhook_id'],
                    'workflow_id' => $ids['workflow_id'],
                    'edit_workflow_id' => $ids['edit_workflow_id'] ?? null,
                    'webhook_auto_setup' => 'success',
                    'webhook_auto_setup_error' => null,
                ],
            ])->save();

            $this->components->info('SUCCESS');
            $this->line('webhook_id: '.$ids['webhook_id']);
            $this->line('workflow_id: '.$ids['workflow_id']);
            $this->line('edit_workflow_id: '.($ids['edit_workflow_id'] ?? 'none'));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $connection->forceFill([
                'meta' => [
                    ...((array) $connection->meta),
                    'webhook_auto_setup' => 'failed',
                    'webhook_auto_setup_error' => $e->getMessage(),
                ],
            ])->save();

            $this->components->error('FAILED: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
