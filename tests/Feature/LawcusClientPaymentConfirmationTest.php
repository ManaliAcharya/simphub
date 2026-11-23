<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Routing\Models\RoutingRule;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;
use Modules\Payment\Events\PaymentApproved;
use Modules\Payment\Listeners\SendPaymentConfirmationListener;
use Modules\Payment\Mail\PaymentConfirmationMail;
use Tests\TestCase;

/** A Lawcus client gets the same thank-you / payment confirmation email as a QuickBooks one. */
class LawcusClientPaymentConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_lawcus_client_gets_the_payment_confirmation_email(): void
    {
        Mail::fake();

        $client = Client::query()->forceCreate([
            'id'            => (string) Str::uuid(),
            'pms_client_id' => (string) Str::uuid(),
            'client_name'   => 'RR Law',
            'client_pms'    => 'LAWCUS',
        ]);

        $invoice = Invoice::query()->forceCreate([
            'pms_source'          => 'lawcus',
            'pms_client_id'       => $client->pms_client_id,
            'external_invoice_id' => 'inv-uuid-1',
            'external_client_id'  => 'contact-1',
            'invoice_number'      => '01748',
            'amount_cents'        => 300,
            'currency'            => 'USD',
            'fund_type'           => 'OPERATING',
            'status'              => 'APPROVED',
            // The contact's second address is the one the payer uses.
            'recipient_emails'    => ['client@example.com', 'Payer@Example.com'],
            'customer'            => ['name' => 'Jane Client', 'email' => 'client@example.com'],
        ]);

        $session = PaymentSession::query()->forceCreate([
            'invoice_id'       => $invoice->id,
            'hosted_url_token' => Str::random(40),
            'status'           => 'COMPLETED',
            'fund_type'        => 'OPERATING',
            'idempotency_key'  => 'idem-'.Str::random(8),
            'link_status'      => 'paid',
            'expires_at'       => now()->addDays(30),
        ]);

        $rule = RoutingRule::query()->forceCreate([
            'merchant_id' => 'default', 'gateway' => 'fluidpay', 'mid' => 'MID-TEST', 'payment_method' => 'CARD',
            'fund_type'   => 'ANY', 'mid_credentials' => ['environment' => 'sandbox'],
        ]);

        $transaction = Transaction::query()->forceCreate([
            'payment_session_id' => $session->id,
            'routing_rule_id'    => $rule->id,
            'mid'                => 'test-mid',
            'gateway_token'      => 'tok_test',
            'invoice_id'     => $invoice->id,
            'gateway'        => 'fluidpay',
            'status'         => 'CAPTURED',
            'fund_type'      => 'OPERATING',
            'amount_cents'   => 300,
            'fee_cents'      => 0,
            'currency'       => 'USD',
            'gateway_txn_id' => 'txn-1',
        ]);

        app(SendPaymentConfirmationListener::class)->handle(new PaymentApproved((string) $transaction->id, []));

        Mail::assertSent(PaymentConfirmationMail::class, 2);
        Mail::assertSent(PaymentConfirmationMail::class, fn ($mail) => $mail->hasTo('client@example.com'));
        Mail::assertSent(PaymentConfirmationMail::class, fn ($mail) => $mail->hasTo('payer@example.com'));
        $this->assertStringContainsString('You paid', (new PaymentConfirmationMail($transaction, $invoice, $client))->render());
    }
}
