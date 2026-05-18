<?php

namespace Modules\Outbound\DTOs;

readonly class RefundRequest
{
    public function __construct(
        public string $gatewayTxnId,
        public string $gatewayToken,
        public int $amountInCents,
        public string $currency = 'USD',
        public array $midCredentials = [],
        public array $metadata = [],
    ) {}
}
