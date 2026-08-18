<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Services\ZohoApiClient;
use Modules\Inbound\Services\ZohoOAuthService;
use Throwable;

/**
 * TEMPORARY diagnostic command — safe to delete once the Zoho token issue is resolved.
 * Calls the lightweight /organizations endpoint to check whether the stored access
 * token still works, and if not, explicitly exercises the refresh-token flow so the
 * two failure modes (dead access token vs. dead refresh token) are told apart.
 */
class ZohoDiagnoseConnectionCommand extends Command
{
    protected $signature = 'zoho:diagnose {pms_client_id}';

    protected $description = 'TEMP: check whether a Zoho connection\'s access/refresh token is still valid.';

    public function handle(ZohoApiClient $api, ZohoOAuthService $oauth): int
    {
        $pmsClientId = (string) $this->argument('pms_client_id');

        $client = Client::query()->where('pms_client_id', $pmsClientId)->first();
        if (! $client) {
            $this->components->error("No client found for pms_client_id [{$pmsClientId}]");

            return self::FAILURE;
        }

        $connection = PmsConnection::query()
            ->where('provider', 'zoho')
            ->where('pms_client_id', $pmsClientId)
            ->latest('created_at')
            ->first();

        if (! $connection) {
            $this->components->error('No Zoho connection found for this client.');

            return self::FAILURE;
        }

        $this->line("Client: {$client->client_name}  (zoho_region: {$client->zoho_region})");
        $this->line('Connection ID: '.$connection->id);
        $this->line('token_expires_at: '.($connection->token_expires_at?->toDateTimeString() ?? 'null'));
        $this->line('last_error: '.($connection->last_error ?? 'none'));
        $this->newLine();

        $this->components->info('Step 1: calling /organizations with the CURRENT access token');
        try {
            $orgs = $api->fetchOrganizations($connection);
            $this->components->info('SUCCESS — access token is currently valid.');
            $this->line(json_encode($orgs, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->warn('Access token call FAILED: '.$e->getMessage());
        }

        $this->newLine();
        $this->components->info('Step 2: access token failed — attempting a refresh-token exchange');
        try {
            $refreshed = $oauth->refreshAccessToken($connection);
            $this->components->info('Refresh call SUCCEEDED.');
            $this->line('New token_expires_at: '.($refreshed->token_expires_at?->toDateTimeString() ?? 'null'));
        } catch (Throwable $e) {
            $this->components->error('Refresh call FAILED: '.$e->getMessage());
            $this->components->error('=> The refresh_token itself is dead (revoked/invalid). The client must fully reconnect Zoho — a code fix cannot recover this.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Step 3: retrying /organizations with the REFRESHED access token');
        try {
            $orgs = $api->fetchOrganizations($refreshed);
            $this->components->info('SUCCESS after refresh — refresh flow works fine; the old access token had simply expired and nothing had triggered a refresh yet.');
            $this->line(json_encode($orgs, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error('STILL FAILED after refresh: '.$e->getMessage());
            $this->components->error('=> Refresh "succeeded" but the resulting token still can\'t call the API — check region/DC mismatch (client_pms zoho_region vs. the account\'s actual data center) or revoked app authorization in Zoho.');

            return self::FAILURE;
        }
    }
}
