<?php

namespace Modules\Payment\Listeners;

use Modules\Payment\Events\PaymentApproved;

class SyncInvoicePaidListener
{
    public function handle(PaymentApproved $event): void
    {
        // Sync invoice state through module contracts or outbound events.
    }
}
