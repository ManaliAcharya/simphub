<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Services\LawcusPaymentSyncService;

class SyncLawcusPaymentsCommand extends Command
{
    protected $signature = 'lawcus:sync-payments {invoice? : Local invoice id or Lawcus invoice number; omit to retry every failed Lawcus sync}';

    protected $description = 'Record captured payments in Lawcus for paid invoices whose sync failed (safe to re-run — already-recorded payments are skipped).';

    public function handle(LawcusPaymentSyncService $sync): int
    {
        $query = Invoice::query()->where('pms_source', 'lawcus')->where('status', 'PAID');

        if ($ref = $this->argument('invoice')) {
            $query->where(fn ($q) => $q->where('id', $ref)->orWhere('invoice_number', $ref));
        } else {
            $query->where('pms_sync_status', 'FAILED');
        }

        $invoices = $query->get();

        if ($invoices->isEmpty()) {
            $this->components->info('No matching paid Lawcus invoices to sync.');

            return self::SUCCESS;
        }

        foreach ($invoices as $invoice) {
            $transaction = Transaction::query()
                ->where('invoice_id', $invoice->id)
                ->where('transaction_type', 'debit')
                ->where('status', 'CAPTURED')
                ->latest()
                ->first();

            if (! $transaction) {
                $this->components->warn("#{$invoice->invoice_number}: no captured transaction found.");

                continue;
            }

            $ok = $sync->sync($transaction, $invoice);

            $ok
                ? $this->components->info("#{$invoice->invoice_number}: recorded in Lawcus.")
                : $this->components->error("#{$invoice->invoice_number}: failed — see audit log (PMS_PAYMENT_RECORD_FAILED).");
        }

        return self::SUCCESS;
    }
}
