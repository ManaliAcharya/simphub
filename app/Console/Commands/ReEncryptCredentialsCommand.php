<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClientMidRoute;
use Modules\Inbound\Models\MindbodySite;

class ReEncryptCredentialsCommand extends Command
{
    protected $signature   = 'credentials:re-encrypt {new-key : The new APP_KEY to encrypt with (base64 encoded)}';
    protected $description = 'Re-encrypt all gateway credentials with a new APP_KEY (use during key rotation)';

    public function handle(): int
    {
        $newKey = $this->argument('new-key');
        $oldKey = config('app.key');

        if ($newKey === $oldKey) {
            $this->error('New key is the same as the current key. Nothing to do.');
            return 1;
        }

        $this->warn('⚠  This will re-encrypt all credentials. Do NOT update APP_KEY in .env until this completes.');
        $this->warn('   Ensure a database backup exists before continuing.');

        if (! $this->confirm('Proceed?')) {
            return 0;
        }

        $total  = 0;
        $errors = 0;

        DB::transaction(function () use ($oldKey, $newKey, &$total, &$errors): void {

            // Client gateway_credentials
            foreach (Client::whereNotNull('gateway_credentials')->cursor() as $client) {
                try {
                    $plain = $client->gateway_credentials;           // decrypt with current key
                    config(['app.key' => $newKey]);
                    $client->gateway_credentials = $plain;           // re-encrypt with new key
                    $client->saveQuietly();
                    config(['app.key' => $oldKey]);
                    $total++;
                } catch (\Throwable $e) {
                    config(['app.key' => $oldKey]);
                    $this->error("Client {$client->pms_client_id}: {$e->getMessage()}");
                    $errors++;
                }
            }

            // ClientMidRoute credentials
            foreach (ClientMidRoute::whereNotNull('credentials')->cursor() as $route) {
                try {
                    $plain = $route->credentials;
                    config(['app.key' => $newKey]);
                    $route->credentials = $plain;
                    $route->saveQuietly();
                    config(['app.key' => $oldKey]);
                    $total++;
                } catch (\Throwable $e) {
                    config(['app.key' => $oldKey]);
                    $this->error("MidRoute {$route->id}: {$e->getMessage()}");
                    $errors++;
                }
            }

            // MindbodySite credentials
            foreach (MindbodySite::cursor() as $site) {
                try {
                    $username   = $site->staffUsername();
                    $password   = $site->staffPassword();
                    $sigKey     = $site->signatureKey();
                    config(['app.key' => $newKey]);
                    $site->staff_username_encrypted       = $username;
                    $site->staff_password_encrypted       = $password;
                    $site->webhook_signature_key_encrypted = $sigKey;
                    $site->saveQuietly();
                    config(['app.key' => $oldKey]);
                    $total++;
                } catch (\Throwable $e) {
                    config(['app.key' => $oldKey]);
                    $this->error("MindbodySite {$site->id}: {$e->getMessage()}");
                    $errors++;
                }
            }
        });

        if ($errors > 0) {
            $this->error("{$errors} record(s) failed. Database rolled back. Investigate before retrying.");
            return 1;
        }

        $this->info("✓ Re-encrypted {$total} record(s) successfully.");
        $this->info("Next step: update APP_KEY={$newKey} in .env and restart the application.");

        return 0;
    }
}
