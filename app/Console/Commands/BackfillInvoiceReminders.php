<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Models\Client;

class BackfillInvoiceReminders extends Command
{
    protected $signature = 'reminders:backfill {pms_client_id}';

    protected $description = 'Populate next_reminder_at for a client\'s existing active payment sessions after reminders are enabled.';

    public function handle(): int
    {
        $pmsClientId = (string) $this->argument('pms_client_id');
        $client      = Client::query()->where('pms_client_id', $pmsClientId)->first();

        if (! $client) {
            $this->components->error("No client found for pms_client_id [{$pmsClientId}].");
            return self::FAILURE;
        }

        $schedule  = $client->reminderScheduleDays();
        $lastOffset = $schedule[count($schedule) - 1];

        $sessions = PaymentSession::query()
            ->where('link_status', 'active')
            ->whereNotNull('first_email_sent_at')
            ->whereHas('invoice', fn ($q) => $q->where('pms_client_id', $pmsClientId))
            ->get();

        $updated = 0;
        $cleared = 0;

        foreach ($sessions as $session) {
            $stepIndex   = $session->reminders_sent_count;
            $daysElapsed = (int) $session->first_email_sent_at->diffInDays(now());

            // Already exhausted, or already past the last cadence step entirely — no
            // retroactive burst for an invoice that's been unpaid since before reminders
            // were turned on.
            if ($stepIndex >= count($schedule) || $daysElapsed >= $lastOffset) {
                $session->forceFill(['next_reminder_at' => null])->save();
                $cleared++;
                continue;
            }

            $session->forceFill([
                'next_reminder_at' => $session->first_email_sent_at->copy()->addDays((int) $schedule[$stepIndex]),
            ])->save();
            $updated++;
        }

        $this->components->info("Backfilled next_reminder_at for {$updated} session(s); cleared {$cleared} exhausted session(s).");

        return self::SUCCESS;
    }
}
