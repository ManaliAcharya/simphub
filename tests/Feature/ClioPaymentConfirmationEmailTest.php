<?php

namespace Tests\Feature;

use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Payment\Mail\PaymentConfirmationMail;
use Tests\TestCase;

/** The Clio bill link is for the firm only — never in the client's payment receipt. */
class ClioPaymentConfirmationEmailTest extends TestCase
{
    public function test_client_receipt_has_no_clio_bill_button(): void
    {
        $invoice = (new Invoice)->forceFill([
            'pms_source'          => 'clio',
            'external_invoice_id' => '1249095803',
            'fund_type'           => 'OPERATING',
            'invoice_number'      => '92',
            'amount_cents'        => 80000,
            'currency'            => 'USD',
        ]);

        $transaction = (new Transaction)->forceFill([
            'gateway'      => 'fluidpay',
            'amount_cents' => 80000,
            'fee_cents'    => 0,
        ]);

        $html = (new PaymentConfirmationMail($transaction, $invoice))->render();

        $this->assertStringContainsString('You paid USD 800.00', $html);
        $this->assertStringNotContainsString('Open bill in Clio', $html);
        $this->assertStringNotContainsString('app.clio.com', $html);
    }
}
