<?php

namespace Modules\Auth\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\ClientAccount;
use Modules\Auth\Services\InvitationService;
use Modules\Inbound\Models\Client;
use Throwable;


class SendClientInvitations extends Command
{
    protected $signature = 'clients:send-invitations
        {--pms-client-id= : Send to a single Inbound client, identified by its pms_client_id}
        {--email= : Send to a single client account, identified by its current email}
        {--set-email= : Set the target account\'s email before sending (use with --pms-client-id or --email; needed when the account still has its client_{id}@example.com placeholder)}';
    protected $description = 'Send invitation emails to client accounts';


    public function __construct(
        protected InvitationService $invitationService
    ) {
        parent::__construct();
    }


    public function handle(): int
    {
        $pmsClientId = $this->option('pms-client-id');
        $targetEmail = $this->option('email');

        if ($pmsClientId || $targetEmail) {
            return $this->sendSingle($pmsClientId, $targetEmail, $this->option('set-email'));
        }

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
                    'client_account_id' => $account->id,
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

    private function sendSingle(?string $pmsClientId, ?string $targetEmail, ?string $setEmail): int
    {
        $query = ClientAccount::query()->whereNull('password_hash');

        if ($pmsClientId) {
            $query->whereHasMorph('owner', [Client::class], function ($q) use ($pmsClientId) {
                $q->where('pms_client_id', $pmsClientId);
            });
        } else {
            $query->where('email_lower', strtolower($targetEmail));
        }

        $account = $query->first();

        if (! $account) {
            $this->error('No matching client account found — either it doesn\'t exist, or it already has a password set (use forgot-password instead).');

            return self::FAILURE;
        }

        if ($setEmail) {
            $account->update([
                'email' => $setEmail,
                'email_lower' => strtolower($setEmail),
            ]);
            $account->refresh();
        }

        if (str_contains($account->email, '@example.com') && ! $setEmail) {
            $this->error("Account {$account->id} still has its placeholder email ({$account->email}). Pass --set-email=real@address.com to set the real one before sending.");

            return self::FAILURE;
        }

        try {
            $this->invitationService->sendInvitationForExistingAccount([
                'client_account_id' => $account->id,
                'email' => $account->email,
                'admin_id' => 0,
            ]);

            $this->info("Invitation sent to {$account->email}.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error('Failed to send invitation: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
