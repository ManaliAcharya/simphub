<?php

namespace Modules\Payment\Listeners;

use Modules\Payment\Events\PaymentApproved;

class TrustAccountingListener
{
    public function handle(PaymentApproved $event): void
    {
        // Publish trust-accounting integration from the event boundary.
    }
}
