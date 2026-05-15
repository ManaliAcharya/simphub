<?php

namespace Modules\Inbound\Services;

use RuntimeException;

class InboundInvoiceProcessor
{
    public function __construct(
        private readonly ClioInvoiceIngestionService $clio,
        private readonly ZohoInvoiceIngestionService $zoho,
        private readonly QuickBooksInvoiceIngestionService $quickbooks,
        private readonly LawcusInvoiceIngestionService $lawcus,
        private readonly CustomInvoiceIngestionService $custom,
    ) {}

    public function process(string $source, string $externalInvoiceId, array $payload = []): array
    {
        return match ($source) {
            'clio'       => $this->clio->ingest($externalInvoiceId, $payload),
            'zoho'       => $this->zoho->ingest($externalInvoiceId, $payload),
            'quickbooks' => $this->quickbooks->ingest($externalInvoiceId, $payload),
            'lawcus'     => $this->lawcus->ingest($externalInvoiceId, $payload),
            'custom'     => $this->custom->ingest($externalInvoiceId, $payload),
            default      => throw new RuntimeException("Unsupported PMS source [{$source}]."),
        };
    }
}
