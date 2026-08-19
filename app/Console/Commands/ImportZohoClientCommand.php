<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TEMPORARY migration tool — companion to zoho:export-client. Reads the
 * JSON export and inserts the client, email config, login account, PMS
 * connection(s), invoices, payment sessions, and transactions into THIS
 * environment's database.
 *
 * clients.id, invoices.id, payment_sessions.id, transactions.id, and
 * client_accounts.id are all UUID primary keys, so rows are inserted
 * with their original IDs intact and every foreign key (invoice_id,
 * payment_session_id, owner_id) lines up automatically — no remapping
 * needed. pms_connections.id and email_configurations.id are
 * auto-increment and aren't referenced by foreign key anywhere in the
 * codebase, so those are dropped and regenerated here.
 *
 * transactions.routing_rule_id is remapped from the source environment's
 * routing rule to an equivalent rule in THIS environment, keyed by the
 * transaction's own `gateway` column via --gateway-rule-map (a JSON
 * object of gateway => routing_rule_id).
 *
 * client_mid_routes (per-client gateway MID overrides) carries an
 * `environment` flag and encrypted credentials that are almost always
 * sandbox/test values in a non-production source — these are exported
 * but only written here if --include-mid-routes is passed explicitly,
 * after you've confirmed they're safe to bring over as-is.
 *
 * The whole import runs in one DB transaction — any failure rolls back
 * completely rather than leaving a half-migrated client behind.
 */
class ImportZohoClientCommand extends Command
{
    protected $signature = 'zoho:import-client {path} {--gateway-rule-map=} {--include-mid-routes} {--dry-run}';

    protected $description = "TEMP: import a client's full data set from a zoho:export-client JSON file into this environment.";

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->components->error("File not found: {$path}");

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload) || ! isset($payload['client'])) {
            $this->components->error('File does not look like a valid zoho:export-client export.');

            return self::FAILURE;
        }

        $defaultMap = json_encode([
            'fluidpay' => '73a9bf1d-3d4d-11f1-bb95-0e01d0119041',
            'paya'     => '98452a0b-a5d5-4ab6-86b5-d0b05939ea06',
            'nmi'      => 'f63b80d2-3d44-11f1-bb95-0e01d0119041',
        ]);
        $gatewayRuleMap = json_decode((string) ($this->option('gateway-rule-map') ?: $defaultMap), true);

        if (! is_array($gatewayRuleMap)) {
            $this->components->error('--gateway-rule-map must be a JSON object of gateway => routing_rule_id.');

            return self::FAILURE;
        }

        $pmsClientId = (string) $payload['pms_client_id'];

        if (DB::table('clients')->where('pms_client_id', $pmsClientId)->exists()) {
            $this->components->error("A client with pms_client_id [{$pmsClientId}] already exists in this database — aborting to avoid duplicating/overwriting it.");

            return self::FAILURE;
        }

        $client           = $payload['client'];
        $emailConfig      = $payload['email_config'] ?? null;
        $clientAccount    = $payload['client_account'] ?? null;
        $midRoutes        = $payload['client_mid_routes'] ?? [];
        $connections      = $payload['pms_connections'] ?? [];
        $invoices         = $payload['invoices'] ?? [];
        $paymentSessions  = $payload['payment_sessions'] ?? [];
        $transactions     = $payload['transactions'] ?? [];

        $includeMidRoutes = (bool) $this->option('include-mid-routes');

        $this->line('About to import:');
        $this->line('  Client: '.$client['client_name'].' ('.$pmsClientId.')');
        $this->line('  email_config: '.($emailConfig ? 1 : 0));
        $this->line('  client_account (login): '.($clientAccount ? 1 : 0));
        $this->line('  pms_connections: '.count($connections));
        $this->line('  invoices: '.count($invoices));
        $this->line('  payment_sessions: '.count($paymentSessions));
        $this->line('  transactions: '.count($transactions));

        if ($midRoutes !== []) {
            if ($includeMidRoutes) {
                $this->line('  client_mid_routes: '.count($midRoutes).' (will be imported — --include-mid-routes set)');
            } else {
                $this->components->warn(
                    '  client_mid_routes: '.count($midRoutes).' found but SKIPPED — pass --include-mid-routes to '
                    .'import them, only after confirming their credentials/environment are correct for this environment.'
                );
            }
        }

        if ($clientAccount && DB::table('client_accounts')->where('email_lower', $clientAccount['email_lower'])->exists()) {
            $this->components->error("A client_accounts row with email [{$clientAccount['email_lower']}] already exists in this database — aborting.");

            return self::FAILURE;
        }

        foreach ($transactions as $t) {
            $gateway = (string) ($t['gateway'] ?? '');
            if (! isset($gatewayRuleMap[$gateway])) {
                $this->components->error("No routing_rule_id mapped for gateway [{$gateway}] (transaction id {$t['id']}). Pass --gateway-rule-map with an entry for it.");

                return self::FAILURE;
            }
            if (! DB::table('routing_rules')->where('id', $gatewayRuleMap[$gateway])->exists()) {
                $this->components->error("routing_rule_id [{$gatewayRuleMap[$gateway]}] mapped for gateway [{$gateway}] does not exist in this database.");

                return self::FAILURE;
            }
        }

        if ($this->option('dry-run')) {
            $this->components->info('Dry run only — nothing written. Re-run without --dry-run to commit.');

            return self::SUCCESS;
        }

        DB::transaction(function () use (
            $client, $emailConfig, $clientAccount, $midRoutes, $includeMidRoutes,
            $connections, $invoices, $paymentSessions, $transactions, $gatewayRuleMap
        ): void {
            DB::table('clients')->insert($client);

            if ($emailConfig) {
                unset($emailConfig['id']);
                DB::table('email_configurations')->insert($emailConfig);
            }

            if ($clientAccount) {
                DB::table('client_accounts')->insert($clientAccount);
            }

            if ($includeMidRoutes) {
                foreach ($midRoutes as $route) {
                    DB::table('client_mid_routes')->insert($route);
                }
            }

            foreach ($connections as $connection) {
                unset($connection['id']);
                DB::table('pms_connections')->insert($connection);
            }

            foreach ($invoices as $invoice) {
                DB::table('invoices')->insert($invoice);
            }

            foreach ($paymentSessions as $session) {
                DB::table('payment_sessions')->insert($session);
            }

            foreach ($transactions as $transaction) {
                $transaction['routing_rule_id'] = $gatewayRuleMap[(string) $transaction['gateway']];
                DB::table('transactions')->insert($transaction);
            }
        });

        $this->components->info('Import complete.');
        $this->components->warn(
            'Next: have the client reconnect Zoho via the normal OAuth flow in this environment. The copied '
            .'access_token/refresh_token were minted by the source environment\'s Zoho app registration — if this '
            .'environment uses a different ZOHO_CLIENT_ID/SECRET, those tokens will not work here regardless of '
            .'encryption. Reconnecting overwrites this same connection row in place (matched by pms_client_id) '
            .'rather than creating a duplicate, and also registers a fresh webhook/workflow pointed at this '
            .'environment\'s callback URL.'
        );
        if ($clientAccount) {
            $this->components->info('client_accounts row imported — the client can log in with their existing password once you send them the portal login link.');
        }

        return self::SUCCESS;
    }
}
