<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Inbound\Models\JobTreadConnection;
use Modules\Inbound\Services\JobTreadInvoiceIngestionService;
use Modules\Inbound\Services\JobTreadSyncService;

/**
 * Runs the JobTread invoice check now instead of waiting for the 5-minute schedule.
 * With --document, also reads that one invoice and says what SimpHub did with it, and why.
 */
class SyncJobTreadCommand extends Command
{
    protected $signature = 'jobtread:sync
        {pms_client_id? : Only this client; omit for every connected JobTread client}
        {--document= : A JobTread invoice id to process and explain (e.g. 22PiqGnZThCb)}';

    protected $description = 'Check JobTread for pending invoices now and email new payment links (same as the 5-minute schedule).';

    public function handle(JobTreadSyncService $sync, JobTreadInvoiceIngestionService $ingestion): int
    {
        $connections = JobTreadConnection::query()
            ->where('provider', JobTreadConnection::PROVIDER)
            ->when($this->argument('pms_client_id'), fn ($q, $id) => $q->where('pms_client_id', $id))
            ->orderByDesc('created_at')
            ->get()
            ->unique('pms_client_id')
            ->values();

        if ($connections->isEmpty()) {
            $this->components->error('No JobTread connection found. Add the grant key on the client\'s JobTread page first.');

            return self::FAILURE;
        }

        $this->line('Webhooks: '.(config('services.jobtread.webhooks_enabled') ? 'on' : 'off')
            .' | Payment write-back: '.(config('services.jobtread.payment_writeback') ? 'on' : 'off')
            .' | Only accounts: '.(implode(', ', (array) config('services.jobtread.only_account_ids')) ?: '-')
            .' | Only recipients: '.(implode(', ', (array) config('services.jobtread.only_recipients')) ?: '-'));

        foreach ($connections as $connection) {
            $meta = (array) ($connection->meta ?? []);
            $this->newLine();
            $this->components->info("Client {$connection->pms_client_id}: ".($meta['organization_name'] ?? 'organization unknown'));

            if ($document = $this->option('document')) {
                $result = $ingestion->ingest((string) $document, [
                    'pms_client_id' => $connection->pms_client_id,
                    'event_name'    => 'manual',
                ]);

                $this->table(['Invoice', 'Result', 'Emails sent', 'SimpHub invoice'], [[
                    $document,
                    $result['skipped'] ?? 'processed',
                    $result['emails_sent'] ?? 0,
                    $result['invoice']?->id ?? '-',
                ]]);
            }

            $counts = $sync->sync($connection->fresh());
            $this->line("Checked {$counts['checked']}, emailed {$counts['emailed']}, failed {$counts['failed']}.");
        }

        return self::SUCCESS;
    }
}
