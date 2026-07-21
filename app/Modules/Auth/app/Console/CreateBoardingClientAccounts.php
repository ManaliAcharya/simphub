<?php

namespace Modules\Auth\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\ClientAccount;
use Modules\Boarding\Models\BoardingClient;
use Throwable;

class CreateBoardingClientAccounts extends Command
{
    protected $signature = 'boarding:create-accounts';

    protected $description = 'Create client accounts for existing boarding clients';

    public function handle(): int
    {
        $this->info('Creating boarding client accounts...');

        $clients = BoardingClient::query()
            ->orderBy('id')
            ->get();

        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($clients as $client) {

            try {

                DB::beginTransaction();

                $exists = ClientAccount::where('owner_type', BoardingClient::class)
                    ->where('owner_id', $client->id)
                    ->exists();

                if ($exists) {
                    $skipped++;

                    DB::rollBack();

                    continue;
                }

                ClientAccount::create([
                    'owner_type' => BoardingClient::class,
                    'owner_id' => $client->id,
                    'email' => $client->contact_email ?? "boarding_{$client->id}@example.com",
                    'email_lower' => strtolower($client->contact_email ?? "boarding_{$client->id}@example.com"),
                    'password_hash' => null,
                    'is_active' => true,
                ]);

                DB::commit();

                $created++;

            } catch (Throwable $e) {

                DB::rollBack();
                report($e);

                $failed++;
                $this->error("Failed Boarding Client ID {$client->id}: " . $e->getMessage());
            }
        }

        $this->table(
            ['Created', 'Skipped', 'Failed'],
            [[$created, $skipped, $failed]]
        );

        $this->info('Boarding client account creation completed.');

        return self::SUCCESS;
    }
}
