<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TEMPORARY migration tool — companion to zoho:export-client /
 * zoho:import-client. For a client that was already imported (so the
 * main import command's "already exists" guard blocks re-running it),
 * this backfills just the client_account and/or client_mid_routes rows
 * from the same export JSON, without touching anything already in
 * place.
 */
class BackfillZohoClientExtrasCommand extends Command
{
    protected $signature = 'zoho:backfill-client-extras {path} {--include-mid-routes} {--dry-run}';

    protected $description = "TEMP: backfill a client's missing client_account/client_mid_routes rows from a zoho:export-client JSON file, for a client already imported into this environment.";

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

        $pmsClientId = (string) $payload['pms_client_id'];
        $client = DB::table('clients')->where('pms_client_id', $pmsClientId)->first();

        if (! $client) {
            $this->components->error("No client with pms_client_id [{$pmsClientId}] exists in this database yet — use zoho:import-client instead.");

            return self::FAILURE;
        }

        $this->line('Client: '.$client->client_name.' ('.$pmsClientId.')  [id: '.$client->id.']');

        $clientAccount = $payload['client_account'] ?? null;
        $midRoutes = $payload['client_mid_routes'] ?? [];
        $includeMidRoutes = (bool) $this->option('include-mid-routes');

        $accountExists = DB::table('client_accounts')
            ->where('owner_type', 'Modules\\Inbound\\Models\\Client')
            ->where('owner_id', $client->id)
            ->exists();

        $willInsertAccount = false;
        if (! $clientAccount) {
            $this->line('client_account: nothing in export file — skipping.');
        } elseif ($accountExists) {
            $this->line('client_account: already exists in this database — skipping.');
        } else {
            $emailTaken = DB::table('client_accounts')->where('email_lower', $clientAccount['email_lower'])->exists();
            if ($emailTaken) {
                $this->components->error("client_account email [{$clientAccount['email_lower']}] is already used by a different account in this database — cannot insert.");

                return self::FAILURE;
            }
            $willInsertAccount = true;
            $this->line('client_account: will insert (email: '.$clientAccount['email'].')');
        }

        $existingRouteKeys = DB::table('client_mid_routes')
            ->where('client_id', $client->id)
            ->get(['route_type', 'gateway'])
            ->map(fn ($r) => $r->route_type.'|'.$r->gateway)
            ->all();

        $routesToInsert = array_values(array_filter(
            $midRoutes,
            fn (array $r) => ! in_array($r['route_type'].'|'.$r['gateway'], $existingRouteKeys, true)
        ));

        if ($midRoutes === []) {
            $this->line('client_mid_routes: nothing in export file — skipping.');
        } elseif (! $includeMidRoutes) {
            $this->components->warn('client_mid_routes: '.count($midRoutes).' found in export but SKIPPED — pass --include-mid-routes after confirming their credentials/environment are correct for this environment.');
        } elseif ($routesToInsert === []) {
            $this->line('client_mid_routes: all rows already present in this database — skipping.');
        } else {
            $this->line('client_mid_routes: will insert '.count($routesToInsert).' of '.count($midRoutes).' (rest already exist).');
        }

        if (! $willInsertAccount && $routesToInsert === []) {
            $this->components->info('Nothing to do.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->info('Dry run only — nothing written. Re-run without --dry-run to commit.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($clientAccount, $willInsertAccount, $routesToInsert): void {
            if ($willInsertAccount) {
                DB::table('client_accounts')->insert($clientAccount);
            }

            foreach ($routesToInsert as $route) {
                DB::table('client_mid_routes')->insert($route);
            }
        });

        $this->components->info('Backfill complete.');

        return self::SUCCESS;
    }
}
