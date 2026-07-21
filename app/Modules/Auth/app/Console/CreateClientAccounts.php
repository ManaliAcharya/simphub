<?php

namespace Modules\Auth\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\ClientAccount;
use Throwable;
use Modules\Inbound\Models\Client;

class CreateClientAccounts extends Command
{
    protected $signature = 'clients:create-accounts';

    protected $description = 'Create client accounts for existing clients';


    public function handle(): int
    {
        $this->info('Creating client accounts...');


        $clients = Client::query()
            ->orderBy('id')
            ->get();

        $created = 0;
        $skipped = 0;
        $failed = 0;


        foreach ($clients as $client) {

            try {

                DB::beginTransaction();

                $exists = ClientAccount::where('owner_type', Client::class)
                    ->where('owner_id', $client->id)
                    ->exists();


                /*
                 |--------------------------------------------------------------------------
                 | Already exists
                 |--------------------------------------------------------------------------
                 */

                if ($exists) {

                    $skipped++;

                    DB::rollBack();

                    continue;
                }


                /*
                 |--------------------------------------------------------------------------
                 | Create account
                 |--------------------------------------------------------------------------
                 |
                 | Temporary email.
                 | Admin will replace later.
                 |
                 */

                ClientAccount::create([
                    'owner_type' => Client::class,
                    'owner_id' => $client->id,
                    'email' => "client_{$client->id}@example.com",
                    'email_lower' => "client_{$client->id}@example.com",
                    'password' => null,
                    'is_active' => true,
                ]);


                DB::commit();


                $created++;


            } catch (Throwable $e) {

                DB::rollBack();
                report($e);

                $failed++;
                $this->error(
                    "Failed Client ID {$client->id}: ".$e->getMessage()
                );
            }
        }



        $this->table(
            ['Created','Skipped','Failed'],
            [
                [
                    $created,
                    $skipped,
                    $failed
                ]
            ]
        );


        $this->info(
            'Client account creation completed.'
        );


        return self::SUCCESS;
    }
}
