<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TEMPORARY migration tool — exports a single client's full data set
 * (client row, email config, PMS connection(s), invoices, payment
 * sessions, transactions) to a JSON file for hand-carrying to another
 * environment via zoho:import-client.
 *
 * Uses the query builder throughout (never Eloquent), so encrypted
 * columns (clients.gateway_credentials, pms_connections.access_token/
 * refresh_token/webhook_secret) are read as their raw ciphertext —
 * never decrypted to plaintext, and never re-encrypted on the way out.
 */
class ExportZohoClientCommand extends Command
{
    protected $signature = 'zoho:export-client {pms_client_id} {--path=}';

    protected $description = "TEMP: export a client's full data set to JSON for migration to another environment.";

    public function handle(): int
    {
        $pmsClientId = (string) $this->argument('pms_client_id');

        $client = DB::table('clients')->where('pms_client_id', $pmsClientId)->first();
        if (! $client) {
            $this->components->error("No client found for pms_client_id [{$pmsClientId}]");

            return self::FAILURE;
        }

        $client = (array) $client;

        $this->line('Client: '.$client['client_name'].'  (client_pms: '.$client['client_pms'].')');

        $emailConfig = DB::table('email_configurations')->where('client_id', $client['id'])->first();

        $connections = DB::table('pms_connections')
            ->where('pms_client_id', $pmsClientId)
            ->get();

        $invoices = DB::table('invoices')->where('pms_client_id', $pmsClientId)->get();
        $invoiceIds = $invoices->pluck('id')->all();

        $paymentSessions = $invoiceIds === []
            ? collect()
            : DB::table('payment_sessions')->whereIn('invoice_id', $invoiceIds)->get();

        $transactions = $invoiceIds === []
            ? collect()
            : DB::table('transactions')->whereIn('invoice_id', $invoiceIds)->get();

        $payload = [
            'pms_client_id'    => $pmsClientId,
            'client'           => $client,
            'email_config'     => $emailConfig ? (array) $emailConfig : null,
            'pms_connections'  => $connections->map(fn ($c) => (array) $c)->all(),
            'invoices'         => $invoices->map(fn ($i) => (array) $i)->all(),
            'payment_sessions' => $paymentSessions->map(fn ($s) => (array) $s)->all(),
            'transactions'     => $transactions->map(fn ($t) => (array) $t)->all(),
        ];

        $path = $this->option('path') ?: storage_path('app/zoho-export-'.$pmsClientId.'.json');
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->components->info('Exported to: '.$path);
        $this->line(
            'Rows: 1 client, '.($emailConfig ? 1 : 0).' email_config, '.$connections->count().' pms_connections, '
            .$invoices->count().' invoices, '.$paymentSessions->count().' payment_sessions, '.$transactions->count().' transactions.'
        );
        $this->components->warn(
            'This file contains encrypted OAuth tokens/credentials as raw ciphertext (not plaintext), but treat it '
            .'as sensitive anyway: copy it to the target server only over a secure channel (scp) and delete it from '
            .'both servers once the import is confirmed working.'
        );

        return self::SUCCESS;
    }
}
