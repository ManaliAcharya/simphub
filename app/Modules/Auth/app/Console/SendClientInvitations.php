<?php

namespace Modules\Auth\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\ClientAccount;
use Modules\Auth\Services\InvitationService;
use Throwable;


class SendClientInvitations extends Command
{
    protected $signature = 'clients:send-invitations';
    protected $description = 'Send invitation emails to client accounts';


    public function __construct(
        protected InvitationService $invitationService
    ) {
        parent::__construct();
    }


    public function handle(): int
    {

        $this->info(
            'Sending invitations...'
        );


        $accounts = ClientAccount::query()
            ->whereNull('password_hash')
            ->whereNotNull('email')
            ->get();



        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($accounts as $account) {

            try {

                DB::beginTransaction();

                /*
                 |--------------------------------------------------------------------------
                 | Skip temporary emails
                 |--------------------------------------------------------------------------
                 */

                if (
                    str_contains(
                        $account->email,
                        '@example.com'
                    )
                ) {

                    $skipped++;

                    DB::rollBack();

                    continue;
                }



                /*
                 |--------------------------------------------------------------------------
                 | Send invitation
                 |--------------------------------------------------------------------------
                 */

                $this->invitationService->sendInvitationForExistingAccount([
                    'client_id' => $account->client_id,
                    'email' => $account->email,
                    'admin_id' => 0,
                ]);

                DB::commit();

                $sent++;



            } catch (Throwable $e) {

                DB::rollBack();
                report($e);

                $failed++;

                $this->error(
                    "Failed Account {$account->id}: ".$e->getMessage()
                );
            }
        }

        $this->table(
            ['Sent','Skipped','Failed'],
            [
                [
                    $sent,
                    $skipped,
                    $failed
                ]
            ]
        );



        return self::SUCCESS;
    }
}
