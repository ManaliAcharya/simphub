<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Models\Transaction;
use Modules\Billing\Services\PaymentSessionService;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\LawcusConnection;
use Modules\Inbound\Services\LawcusOutstandingInvoices;
use Modules\Inbound\Services\LawcusPaymentSyncService;
use Modules\Outbound\Contracts\GatewayAdapterInterface;
use Modules\Outbound\DTOs\ChargeRequest;
use Modules\Outbound\DTOs\GatewayResponse;
use Modules\Outbound\Factory\GatewayAdapterFactory;
use Modules\Payment\Events\PaymentApproved;
use Modules\Routing\Models\RoutingRule;
use Tests\TestCase;

/**
 * Lawcus "show outstanding invoices": the payer pays either this invoice or everything the
 * same Lawcus client still owes, in one charge recorded on each invoice in Lawcus.
 */
class LawcusPayAllOutstandingTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.us.lawcus.com';

    private Client $client;

    private Invoice $invoice;

    /** Lawcus invoices by id: amount_due and recorded payments, changed by recorded payments. */
    private array $lawcus = [];

    /** @var list<array> payments POSTed to Lawcus */
    private array $recorded = [];

    private ?int $chargedCents = null;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config()->set('services.lawcus.rest_api_base_url', self::API);

        $this->client = Client::query()->forceCreate([
            'id'                             => (string) Str::uuid(),
            'pms_client_id'                  => (string) Str::uuid(),
            'client_name'                    => 'RR Law',
            'client_pms'                     => 'LAWCUS',
            'fee_surcharge_enabled'          => true,
            'cc_fee_percent'                 => '3.00000',
            'lawcus_default_bank_account_id' => 'acct-operating',
        ]);

        LawcusConnection::query()->create([
            'provider'      => 'lawcus',
            'pms_client_id' => $this->client->pms_client_id,
            'access_token'  => 'api-token',
        ]);

        $this->invoice = $this->lawcusInvoice('inv-main', '00040', 10000, ['show_outstanding_invoices' => true]);

        $this->lawcus = [
            'inv-main' => ['amount_due' => '100.00', 'payments' => []],
            'inv-2'    => ['amount_due' => '50.00', 'payments' => []],
            'inv-3'    => ['amount_due' => '25.00', 'payments' => []],
        ];

        $this->fakeLawcus();
    }

    public function test_the_clients_other_unpaid_invoices_are_offered(): void
    {
        $outstanding = app(LawcusOutstandingInvoices::class)->for($this->invoice);

        $this->assertSame(17500, $outstanding['total_cents']);
        $this->assertSame(['00041', '00042'], array_column($outstanding['invoices'], 'invoice_number'));
        $this->assertSame([5000, 2500], array_column($outstanding['invoices'], 'amount_cents'));
    }

    public function test_nothing_extra_is_offered_without_the_toggle_or_for_trust_requests(): void
    {
        $off   = $this->lawcusInvoice('inv-off', '00050', 1000, ['show_outstanding_invoices' => false]);
        $trust = $this->lawcusInvoice('inv-trust', '00051', 1000, ['show_outstanding_invoices' => true], 'TRUST');

        $this->assertNull(app(LawcusOutstandingInvoices::class)->for($off));
        $this->assertNull(app(LawcusOutstandingInvoices::class)->for($trust));
    }

    public function test_invoices_already_paid_through_us_are_left_out(): void
    {
        $this->lawcusInvoice('inv-2', '00041', 5000)->forceFill(['status' => 'PAID'])->save();

        $outstanding = app(LawcusOutstandingInvoices::class)->for($this->invoice);

        $this->assertSame(['00042'], array_column($outstanding['invoices'], 'invoice_number'));
        $this->assertSame(12500, $outstanding['total_cents']);
    }

    public function test_paying_all_outstanding_charges_the_total_with_the_fee_and_closes_the_other_links(): void
    {
        Event::fake([PaymentApproved::class]);
        $rule = $this->approvingGateway();

        // We hold #00041 too, with its own open link and a reminder due.
        $other        = $this->lawcusInvoice('inv-2', '00041', 5000);
        $otherSession = app(PaymentSessionService::class)->create($other);
        $otherSession->forceFill(['next_reminder_at' => now()->addDay()])->save();

        $this->submit($rule, ['pay_scope' => 'outstanding', 'expected_amount_cents' => 17500])
            ->assertOk()
            ->assertJsonPath('amount_cents', 18025)
            ->assertJsonPath('covered_invoices.0.invoice_number', '00041');

        $this->assertSame(18025, $this->chargedCents, '175.00 plus 3%');

        $transaction = Transaction::query()->firstOrFail();
        $this->assertSame(525, (int) $transaction->fee_cents);
        $this->assertSame(['inv-2', 'inv-3'], array_column($transaction->covered_invoices, 'external_invoice_id'));

        $this->assertSame('PAID', $other->fresh()->status);
        $this->assertSame('paid', $otherSession->fresh()->link_status);
        $this->assertNull($otherSession->fresh()->next_reminder_at);
    }

    public function test_a_changed_balance_is_refused_before_charging(): void
    {
        $rule = $this->approvingGateway();

        $this->submit($rule, ['pay_scope' => 'outstanding', 'expected_amount_cents' => 17000])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The outstanding balance has changed. Please reload the page to see the current amount.');

        $this->assertNull($this->chargedCents);
    }

    public function test_paying_only_this_invoice_is_unchanged(): void
    {
        Event::fake([PaymentApproved::class]);
        $rule = $this->approvingGateway();

        $this->submit($rule)->assertOk()->assertJsonPath('amount_cents', 10300);

        $this->assertNull(Transaction::query()->firstOrFail()->covered_invoices);
    }

    public function test_the_payment_is_recorded_on_each_invoice_in_lawcus_once(): void
    {
        $transaction = $this->outstandingTransaction();

        $this->assertTrue(app(LawcusPaymentSyncService::class)->sync($transaction, $this->invoice));

        $this->assertSame(
            ['inv-main' => 100.0, 'inv-2' => 50.0, 'inv-3' => 25.0],
            collect($this->recorded)->mapWithKeys(fn ($p) => [$p['invoice_id'] => (float) $p['amount']])->all(),
        );
        $this->assertSame('SYNCED', $this->invoice->fresh()->pms_sync_status);

        // A retry finds every payment already recorded.
        $this->assertTrue(app(LawcusPaymentSyncService::class)->sync($transaction, $this->invoice));
        $this->assertCount(3, $this->recorded);
    }

    public function test_a_covered_invoice_paid_meanwhile_is_flagged_not_failed(): void
    {
        $this->lawcus['inv-3']['amount_due'] = '0.00';
        $transaction = $this->outstandingTransaction();

        $this->assertTrue(app(LawcusPaymentSyncService::class)->sync($transaction, $this->invoice));

        $this->assertSame(['inv-main', 'inv-2'], array_column($this->recorded, 'invoice_id'));
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'LAWCUS_COVERED_INVOICE_ALREADY_PAID']);
    }

    private function outstandingTransaction(): Transaction
    {
        $session = app(PaymentSessionService::class)->create($this->invoice);
        $rule    = RoutingRule::query()->forceCreate([
            'merchant_id' => 'default', 'gateway' => 'nmi', 'mid' => 'MID-TEST', 'payment_method' => 'CARD', 'fund_type' => 'ANY',
            'mid_credentials' => ['environment' => 'sandbox'],
        ]);

        return Transaction::query()->create([
            'payment_session_id' => $session->id,
            'invoice_id'         => $this->invoice->id,
            'routing_rule_id'    => $rule->id,
            'gateway'            => 'nmi',
            'mid'                => 'MID-TEST',
            'gateway_txn_id'     => 'txn-1',
            'gateway_token'      => '',
            'status'             => 'CAPTURED',
            'transaction_type'   => 'debit',
            'fund_type'          => 'OPERATING',
            'amount_cents'       => 18025,
            'fee_cents'          => 525,
            'currency'           => 'USD',
            'covered_invoices'   => [
                ['external_invoice_id' => 'inv-2', 'invoice_number' => '00041', 'amount_cents' => 5000, 'due_date' => null, 'matter_id' => null],
                ['external_invoice_id' => 'inv-3', 'invoice_number' => '00042', 'amount_cents' => 2500, 'due_date' => null, 'matter_id' => null],
            ],
        ]);
    }

    private function lawcusInvoice(string $externalId, string $number, int $cents, array $data = [], string $fundType = 'OPERATING'): Invoice
    {
        return Invoice::query()->forceCreate([
            'pms_source'          => 'lawcus',
            'pms_client_id'       => $this->client->pms_client_id,
            'external_invoice_id' => $externalId,
            'external_client_id'  => '9',
            'invoice_number'      => $number,
            'status'              => 'SENT',
            'fund_type'           => $fundType,
            'amount_cents'        => $cents,
            'currency'            => 'USD',
            'recipient_emails'    => ['client@example.com'],
            'raw_payload'         => ['invoice' => ['data' => ['id' => $externalId, 'client_id' => 9] + $data]],
        ]);
    }

    private function fakeLawcus(): void
    {
        Http::fake(function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_starts_with($path, '/reports/accounts-receivable')) {
                return Http::response([
                    '9' => [
                        ['id' => 'inv-main', 'number' => '00040', 'client_id' => 9, 'amount_due' => '100.00', 'due_date' => '2026-11-20 00:00:00'],
                        ['id' => 'inv-3', 'number' => '00042', 'client_id' => 9, 'amount_due' => '25.00', 'due_date' => '2026-12-01 00:00:00'],
                        ['id' => 'inv-2', 'number' => '00041', 'client_id' => 9, 'amount_due' => '50.00', 'due_date' => '2026-11-25 00:00:00'],
                        ['id' => 'inv-zero', 'number' => '00039', 'client_id' => 9, 'amount_due' => '0.00'],
                    ],
                    '10' => [['id' => 'inv-other-client', 'number' => '00099', 'client_id' => 10, 'amount_due' => '80.00']],
                ]);
            }

            if (preg_match('#^/invoices/(.+)$#', $path, $m)) {
                $id = urldecode($m[1]);

                return Http::response(['invoice' => ['id' => $id, 'client_id' => 9, 'amount_due' => $this->lawcus[$id]['amount_due']], 'payments' => $this->lawcus[$id]['payments']]);
            }

            if ($path === '/accounts/transactions' && $request->method() === 'POST') {
                foreach ((array) $request['payments'] as $payment) {
                    $this->recorded[] = $payment;
                    $this->lawcus[$payment['invoice_id']]['payments'][] = ['note' => $payment['note'], 'amount' => $payment['amount']];
                    $this->lawcus[$payment['invoice_id']]['amount_due'] = number_format((float) $this->lawcus[$payment['invoice_id']]['amount_due'] - (float) $payment['amount'], 2, '.', '');
                }

                return Http::response(['ok' => true]);
            }

            return Http::response(['id' => 1]);
        });
    }

    private function approvingGateway(): RoutingRule
    {
        $adapter = Mockery::mock(GatewayAdapterInterface::class);
        $adapter->shouldReceive('charge')->andReturnUsing(function (ChargeRequest $request) {
            $this->chargedCents = $request->amountInCents;

            return GatewayResponse::approved('txn-1', 'tok-1');
        });
        $this->mock(GatewayAdapterFactory::class, fn ($mock) => $mock->shouldReceive('make')->andReturn($adapter));

        return RoutingRule::query()->forceCreate([
            'merchant_id'     => 'default',
            'gateway'         => 'nmi',
            'mid'             => 'MID-TEST',
            'payment_method'  => 'CARD',
            'fund_type'       => 'ANY',
            'mid_credentials' => ['environment' => 'sandbox', 'security_key' => 'sk-test'],
        ]);
    }

    private function submit(RoutingRule $rule, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $session = PaymentSession::query()->where('invoice_id', $this->invoice->id)->first()
            ?? app(PaymentSessionService::class)->create($this->invoice);

        return $this->postJson("/api/v1/payment/sessions/{$session->hosted_url_token}/submit", [
            'token'           => 'tok_test',
            'routing_rule_id' => $rule->id,
            'payment_method'  => 'CARD',
            'first_name'      => 'Pat',
            'last_name'       => 'Payer',
            'billing_address' => ['address1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701'],
        ] + $extra, ['X-Checkout-Visit' => (string) Str::uuid()]);
    }
}
