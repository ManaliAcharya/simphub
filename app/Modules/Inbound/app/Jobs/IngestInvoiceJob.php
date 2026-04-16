<?php

namespace Modules\Inbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Inbound\DTOs\WebhookEvent;
use Modules\Inbound\Services\ClioInvoiceIngestionService;

class IngestInvoiceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public WebhookEvent $event,
    ) {}

    public function handle(ClioInvoiceIngestionService $clio): void
    {
        if ($this->event->source !== 'clio') {
            return;
        }

        $invoiceId = (string) (
            data_get($this->event->payload, 'data.id')
            ?? data_get($this->event->payload, 'data.bill.id')
            ?? data_get($this->event->payload, 'id')
        );

        if ($invoiceId === '') {
            \Log::warning('Clio webhook missing invoice id.', [
                'event' => $this->event->eventName,
                'payload' => $this->event->payload,
            ]);

            return;
        }

        $clio->ingest($invoiceId, $this->event->payload);
    }
}
