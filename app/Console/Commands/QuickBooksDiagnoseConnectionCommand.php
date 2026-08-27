<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\QuickBooksConnection;
use Modules\Inbound\Services\QuickBooksApiClient;
use Modules\Inbound\Services\QuickBooksOAuthService;
use Throwable;

/**
 * TEMPORARY diagnostic command — safe to delete once QB webhook delivery is resolved.
 *
 * Unlike Zoho, QuickBooks has no per-connection webhook subscription API — webhook
 * delivery is a single, app-wide setting configured manually on developer.intuit.com
 * (separately for the Development and Production key sets). This command can't query
 * that config, so it focuses on everything that CAN be checked from inside the app:
 * connection health (token/refresh), which environment + realm this client is on, and
 * the exact callback URL + verifier-token presence that must match the Intuit dashboard.
 */
class QuickBooksDiagnoseConnectionCommand extends Command
{
    protected $signature = 'qb:diagnose {pms_client_id}';

    protected $description = 'TEMP: check a QuickBooks connection\'s health and print what must match the Intuit developer dashboard for webhooks to arrive.';

    public function handle(QuickBooksApiClient $api, QuickBooksOAuthService $oauth): int
    {
        $pmsClientId = (string) $this->argument('pms_client_id');

        $client = Client::query()->where('pms_client_id', $pmsClientId)->first();
        if (! $client) {
            $this->components->error("No client found for pms_client_id [{$pmsClientId}]");

            return self::FAILURE;
        }

        $connection = QuickBooksConnection::query()
            ->where('provider', 'quickbooks')
            ->where('pms_client_id', $pmsClientId)
            ->latest('created_at')
            ->first();

        if (! $connection) {
            $this->components->error('No QuickBooks connection found for this client.');

            return self::FAILURE;
        }

        $this->line("Client: {$client->client_name}");
        $this->line('Connection ID: '.$connection->id);
        $this->line('realm_id (Company ID): '.($connection->realmId() ?: 'MISSING'));
        $this->line('environment: '.$connection->environment().' <-- must match which Intuit key-set/tab (Development vs Production) is configured for webhooks');
        $this->line('company_name: '.($connection->companyName() ?: 'unknown'));
        $this->line('token_expires_at: '.($connection->token_expires_at?->toDateTimeString() ?? 'null'));
        $this->line('last_error: '.($connection->last_error ?? 'none'));
        $this->newLine();

        $this->components->info('Step 1: calling QuickBooks (list income accounts) with the CURRENT access token');
        $working = $connection;
        try {
            $accounts = $api->fetchIncomeAccounts($connection);
            $this->components->info('SUCCESS — access token is currently valid and can reach the QuickBooks API.');
            $this->line(count($accounts).' income account(s) returned.');
        } catch (Throwable $e) {
            $this->components->warn('API call FAILED: '.$e->getMessage());
            $working = null;
        }

        if (! $working) {
            $this->newLine();
            $this->components->info('Step 2: attempting an explicit refresh-token exchange');
            try {
                $refreshed = $oauth->refreshAccessToken($connection);
                $this->components->info('Refresh call SUCCEEDED.');
                $this->line('New token_expires_at: '.($refreshed->token_expires_at?->toDateTimeString() ?? 'null'));
            } catch (Throwable $e) {
                $this->components->error('Refresh call FAILED: '.$e->getMessage());
                $this->components->error('=> The refresh_token itself is dead (revoked/invalid). The client must fully reconnect QuickBooks — a code fix cannot recover this. This alone would NOT explain missing webhooks though, since webhook delivery from Intuit does not depend on this app\'s tokens.');

                return self::FAILURE;
            }

            $this->newLine();
            $this->components->info('Step 3: retrying with the REFRESHED access token');
            try {
                $accounts = $api->fetchIncomeAccounts($refreshed);
                $this->components->info('SUCCESS after refresh — the connection itself is healthy.');
                $this->line(count($accounts).' income account(s) returned.');
            } catch (Throwable $e) {
                $this->components->error('STILL FAILED after refresh: '.$e->getMessage());
                $this->components->error('=> Refresh "succeeded" but the resulting token still can\'t call the API — check for revoked app authorization on the QuickBooks side.');

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->components->info('Step 4: webhook config that MUST match the Intuit developer dashboard');
        $this->components->warn('QuickBooks has no API to register or query webhook subscriptions per-connection — this is a one-time setting on developer.intuit.com, configured separately under the "Development" and "Production" key tabs. Nothing below can be verified automatically; cross-check it by hand.');
        $this->newLine();

        $callbackUrl = (string) config('services.quickbooks.webhook_callback_url');
        $verifierToken = (string) config('services.quickbooks.webhook_verifier_token');

        $this->line('Expected webhook callback URL (app config): '.$callbackUrl);
        $this->line('QB_WEBHOOK_VERIFIER_TOKEN configured: '.($verifierToken !== '' ? 'yes (value hidden)' : 'NO — MISSING. Signature verification is silently skipped when this is blank.'));
        $this->line('Connection environment: '.$connection->environment());

        $this->newLine();
        $this->components->info('On developer.intuit.com, under this app\'s "'.($connection->environment() === 'production' ? 'Production' : 'Development').'" keys tab -> Webhooks:');
        $this->line('  1. Confirm the "Endpoint URL" exactly equals: '.$callbackUrl);
        $this->line('  2. Confirm a Verifier Token is set there and matches QB_WEBHOOK_VERIFIER_TOKEN in this environment\'s .env exactly (whitespace included).');
        $this->line('  3. Confirm "Invoice" (and any other entities you rely on) are checked in the subscribed entity list.');
        $this->line('  4. Confirm this realm_id ('.$connection->realmId().') is actually authorized against this same app/key-set — a company connected under the wrong key-set (e.g. sandbox company hitting production keys) never fires webhooks either.');
        $this->newLine();
        $this->line('Separately, check application logs for "QuickBooks webhook: received" around the time an invoice was created — its absence means Intuit never called the endpoint at all (points at the dashboard config above); its presence with a 401 afterward points at a verifier-token mismatch instead.');

        return self::SUCCESS;
    }
}
