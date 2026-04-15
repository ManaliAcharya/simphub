<?php

namespace Modules\Inbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Inbound\DTOs\WebhookEvent;

class IngestInvoiceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public WebhookEvent $event,
    ) {}

    public function handle(): void
    {
        // Invoice ingestion will normalize webhook payloads into Billing-owned records.
    }
}
