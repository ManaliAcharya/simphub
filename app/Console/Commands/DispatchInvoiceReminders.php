<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Models\Client;
use Modules\Payment\Jobs\SendInvoiceReminder;

class DispatchInvoiceReminders extends Command
{
    protected $signature = 'reminders:dispatch';

    protected $description = 'Dispatch a queued reminder job for every active payment session whose next reminder is due.';

    public function handle(): int
    {
        Log::info('reminders:dispatch invoked', ['at' => now()->toDateTimeString()]);

        // Invoice has no formal `client()` relation (pms_client_id is a business key, not
        // a foreign key) — resolve the enabled-client id list up front instead.
        $enabledClientIds = Client::query()->where('reminders_enabled', true)->pluck('pms_client_id');

        Log::info('reminders:dispatch enabled clients resolved', [
            'enabled_client_count' => $enabledClientIds->count(),
            'enabled_client_ids'   => $enabledClientIds->all(),
        ]);

        $dispatched = 0;

        PaymentSession::query()
            ->where('link_status', 'active')
            ->whereNotNull('next_reminder_at')
            ->where('next_reminder_at', '<=', now())
            ->whereHas('invoice', fn ($q) => $q->whereIn('pms_client_id', $enabledClientIds))
            ->orderBy('next_reminder_at')
            ->chunkById(200, function ($sessions) use (&$dispatched): void {
                foreach ($sessions as $session) {
                    Log::info('reminders:dispatch queuing SendInvoiceReminder', [
                        'payment_session_id' => $session->id,
                        'next_reminder_at'    => $session->next_reminder_at?->toDateTimeString(),
                    ]);

                    SendInvoiceReminder::dispatch($session->id);
                    $dispatched++;
                }
            });

        Log::info('reminders:dispatch finished', ['dispatched' => $dispatched]);

        $this->components->info("Dispatched {$dispatched} invoice reminder job(s).");

        return self::SUCCESS;
    }
}
