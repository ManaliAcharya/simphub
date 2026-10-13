<?php

namespace Modules\Inbound\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Inbound\Models\Subscription;
use Modules\Inbound\Services\SubscriptionService;

class BillSubscriptionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly int $subscriptionId,
    ) {}

    public function handle(SubscriptionService $subscriptions): void
    {
        $subscription = Subscription::query()->find($this->subscriptionId);

        if (! $subscription || $subscription->isCancelled()) {
            return;
        }

        $subscriptions->billDuePeriod($subscription, CarbonImmutable::today());
    }
}
