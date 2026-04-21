<?php

namespace Modules\Routing\DTOs;

readonly class RoutingContext
{
    public function __construct(
        public string $merchantId,
        public int $amountInCents,
        public string $paymentMethod,
        public string $fundType,
        public string $sessionId,
        public string $currency = 'USD',
        public array $metadata = [],
    ) {}
}
