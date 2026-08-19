<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TEMPORARY migration tool — companion to zoho:export-client. Reads the
 * JSON export and inserts the client, email config, PMS connection(s),
 * invoices, payment sessions, and transactions into THIS environment's
 * database.
 *
 * clients.id, invoices.id, payment_sessions.id, transactions.id are all
 * UUID primary keys, so rows are inserted with their original IDs intact
 * and every foreign key (invoice_id, payment_session_id) lines up
 * automatically — no remapping needed. pms_connections.id and
 * email_configurations.id are auto-increment and aren't referenced by
 * foreign key anywhere in the codebase, so those are dropped and
 * regenerated here.
 *
 * transactions.routing_rule_id is the one real exception: it's remapped
 * from the source environment's routing rule to an equivalent rule in
 * THIS environment, keyed by the transaction's own `gateway` column via
 * --gateway-rule-map (a JSON object of gateway => routing_rule_id).
 *
 * The whole import runs in one DB transaction — any failure rolls back
 * completely rather than leaving a half-migrated client behind.
 */
class ImportZohoClientCommand extends Command
{
    protected $signature = 'zoho:import-client {path} {--gateway-rule-map=} {--dry-run}';

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
        $connections      = $payload['pms_connections'] ?? [];
        $invoices         = $payload['invoices'] ?? [];
        $paymentSessions  = $payload['payment_sessions'] ?? [];
        $transactions     = $payload['transactions'] ?? [];

        $this->line('About to import:');
        $this->line('  Client: '.$client['client_name'].' ('.$pmsClientId.')');
        $this->line('  email_config: '.($emailConfig ? 1 : 0));
        $this->line('  pms_connections: '.count($connections));
        $this->line('  invoices: '.count($invoices));
        $this->line('  payment_sessions: '.count($paymentSessions));
        $this->line('  transactions: '.count($transactions));

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

        DB::transaction(function () use ($client, $emailConfig, $connections, $invoices, $paymentSessions, $transactions, $gatewayRuleMap): void {
            DB::table('clients')->insert($client);

            if ($emailConfig) {
                unset($emailConfig['id']);
                DB::table('email_configurations')->insert($emailConfig);
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
            "Next: re-run Zoho webhook/workflow setup against THIS environment's callback URL — the copied "
            .'connection still has meta pointing at the source environment\'s webhook_id/workflow_id, which POSTs '
            .'to the source domain, not this one. The OAuth token was copied as raw ciphertext (same APP_KEY '
            .'confirmed), so it should decrypt and work here without the client needing to reconnect via OAuth.'
        );

        return self::SUCCESS;
    }
}
