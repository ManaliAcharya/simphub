<?php

namespace Modules\Routing\DTOs;

readonly class RoutingContext
{
    public function __construct(
        public int $amountInCents,
        public string $currency = 'USD',
        public array $metadata = [],
    ) {}
}
