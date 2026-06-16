<?php

namespace Modules\BookSync\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\BookSync\Models\BookSyncTransaction;
use Modules\BookSync\Services\BookSyncPostingService;

class PostTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(
        public readonly BookSyncTransaction $transaction,
    ) {}

    public function handle(BookSyncPostingService $posting): void
    {
        $txn = $this->transaction->fresh();

        if (! $txn || in_array($txn->status, ['posted', 'already_posted'], true)) {
            return;
        }

        $result = $posting->post($txn);

        $txn->batch->recalculateCounts();

        if ($result->status === 'failed' && ! $result->hasExhaustedRetries() && $result->next_retry_at) {
            self::dispatch($result)->delay($result->next_retry_at);

            Log::info('BookSync: transaction queued for retry', [
                'reference'   => $result->reference,
                'retry_count' => $result->retry_count,
                'next_retry'  => $result->next_retry_at->toIso8601String(),
            ]);
        } elseif ($result->status === 'permanently_failed') {
            Log::error('BookSync: transaction permanently failed after 7 attempts', [
                'reference'   => $result->reference,
                'merchant_id' => $result->merchant->merchant_id,
                'error'       => $result->error_message,
            ]);
        }
    }
}
