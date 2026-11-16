<?php

namespace Modules\Inbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Services\ZohoBooksSubscriptionSyncService;

/**
 * Writes one paid Zoho CRM subscription invoice to Zoho Books. Each try resumes from
 * the last step saved on the invoice (see ZohoBooksSubscriptionSyncService).
 */
class SyncZohoBooksPaymentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> seconds between tries */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(
        public readonly string $invoiceId,
        public readonly string $transactionId,
    ) {}

    /** Two runs for the same invoice never overlap (e.g. a retry while the first is still running). */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('zoho-books-sync:'.$this->invoiceId))->releaseAfter(60)->expireAfter(300)];
    }

    public function handle(ZohoBooksSubscriptionSyncService $books): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);
        $transaction = Transaction::query()->find($this->transactionId);

        if (! $invoice || ! $transaction) {
            return;
        }

        $books->sync($invoice, $transaction);
    }
}
