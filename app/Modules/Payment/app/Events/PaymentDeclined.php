<?php

namespace Modules\Payment\Events;

class PaymentDeclined
{
    public function __construct(
        public string $transactionReference,
        public array $payload = [],
    ) {}
}
