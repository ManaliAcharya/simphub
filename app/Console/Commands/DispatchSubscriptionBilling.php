<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Inbound\Jobs\BillSubscriptionJob;
use Modules\Inbound\Models\Subscription;

class DispatchSubscriptionBilling extends Command
{
    protected $signature = 'subscriptions:bill';

    protected $description = 'Queue the monthly payment link for every open subscription whose next billing date has arrived.';

    public function handle(): int
    {
        $dispatched = 0;

        // Runs daily so a missed 1st (downtime) is picked up on the next run.
        Subscription::query()
            ->open()
            ->whereNotNull('next_billing_on')
            ->whereDate('next_billing_on', '<=', today())
            ->chunkById(200, function ($subscriptions) use (&$dispatched): void {
                foreach ($subscriptions as $subscription) {
                    BillSubscriptionJob::dispatch($subscription->id);
                    $dispatched++;
                }
            });

        Log::info('subscriptions:bill finished', ['dispatched' => $dispatched]);
        $this->info("Queued {$dispatched} subscription(s) for billing.");

        return self::SUCCESS;
    }
}
