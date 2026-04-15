<?php

namespace Modules\Inbound\DTOs;

readonly class StandardInvoice
{
    public function __construct(
        public string $externalId,
        public string $matterReference,
        public int $amountInCents,
        public string $currency = 'USD',
        public array $metadata = [],
    ) {}
}
