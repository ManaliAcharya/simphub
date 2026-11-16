<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Billing\Models\Invoice;
use Modules\Inbound\Services\ZohoBooksSubscriptionSyncService;
use Throwable;

class ZohoBooksSubscriptionSyncCommand extends Command
{
    protected $signature = 'zohocrm:books-sync
        {invoice? : SimpHub invoice ID to sync now}
        {--client= : With --failed, only this pms_client_id}
        {--failed : Queue a retry for every paid invoice whose Books sync failed}';

    protected $description = 'Create the Zoho Books invoice and payment for paid Zoho CRM subscription invoices.';

    public function handle(ZohoBooksSubscriptionSyncService $books): int
    {
        if ($invoiceId = $this->argument('invoice')) {
            $invoice = Invoice::query()->find($invoiceId);
            $transaction = $invoice ? $books->approvedTransaction($invoice) : null;

            if (! $invoice || ! $transaction) {
                $this->components->error('No invoice with an approved transaction found for that ID.');

                return self::FAILURE;
            }

            if (! $books->shouldSync($invoice)) {
                $this->components->info('Nothing to do: already in Zoho Books, or Books sync is off for this client.');

                return self::SUCCESS;
            }

            try {
                $books->sync($invoice, $transaction);
            } catch (Throwable $e) {
                $this->components->error('Zoho Books sync failed: '.$e->getMessage());

                return self::FAILURE;
            }

            $this->components->info('Synced to Zoho Books.');

            return self::SUCCESS;
        }

        if (! $this->option('failed')) {
            $this->components->error('Pass an invoice ID, or --failed to retry every failed sync.');

            return self::INVALID;
        }

        $queued = $books->queueFailed($this->option('client') ?: null);

        $this->components->info("Queued {$queued} Zoho Books sync ".($queued === 1 ? 'retry' : 'retries').'.');

        return self::SUCCESS;
    }
}
