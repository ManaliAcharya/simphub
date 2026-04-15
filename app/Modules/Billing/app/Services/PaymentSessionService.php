<?php

namespace Modules\Billing\Services;

use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;

class PaymentSessionService
{
    public function createForInvoice(Invoice $invoice, array $attributes = []): PaymentSession
    {
        return new PaymentSession([
            'invoice_id' => $invoice->getKey(),
            ...$attributes,
        ]);
    }
}
