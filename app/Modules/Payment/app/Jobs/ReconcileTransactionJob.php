<?php

namespace Modules\Payment\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileTransactionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $transactionReference,
    ) {}

    public function handle(): void
    {
        // Keep only orchestration glue here if Payment continues to own reconciliation dispatch.
    }
}
