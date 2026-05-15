<?php

namespace Modules\Outbound\DTOs;

readonly class ChargeRequest
{
    public function __construct(
        public string $token,
        public int $amountInCents,
        public string $currency = 'USD',
        public string $idempotencyKey = '',
        public array $midCredentials = [],
        public array $billing = [],
        public array $metadata = [],
        public string $transactionType = 'debit',
    ) {}
}
