<?php

namespace Modules\Inbound\Services;

use RuntimeException;

class InboundInvoiceProcessor
{
    public function __construct(
        private readonly ClioInvoiceIngestionService $clio,
    ) {}

    public function process(string $source, string $externalInvoiceId, array $payload = []): array
    {
        return match ($source) {
            'clio' => $this->clio->ingest($externalInvoiceId, $payload),
            default => throw new RuntimeException("Unsupported PMS source [{$source}]."),
        };
    }
}
