<?php

namespace Modules\Payment\Events;

class PaymentApproved
{
    public function __construct(
        public string $transactionReference,
        public array $payload = [],
    ) {}
}
