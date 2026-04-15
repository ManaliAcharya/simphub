<?php

namespace Modules\Outbound\DTOs;

readonly class ChargeRequest
{
    public function __construct(
        public int $amountInCents,
        public string $currency = 'USD',
        public array $billing = [],
        public array $metadata = [],
    ) {}
}
