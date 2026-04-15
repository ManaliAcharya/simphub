<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ExpirePaymentSessionsCommand extends Command
{
    protected $signature = 'payments:expire-sessions';

    protected $description = 'Expire stale payment sessions.';

    public function handle(): int
    {
        $this->components->info('Payment session expiration placeholder executed.');

        return self::SUCCESS;
    }
}
