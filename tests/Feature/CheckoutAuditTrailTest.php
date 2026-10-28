<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Audit\Models\AuditLog;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Payment\Models\IdempotencyKey;
use Modules\Payment\Services\PaymentCheckoutService;
use Modules\Routing\Models\RoutingRule;
use Tests\TestCase;

class CheckoutAuditTrailTest extends TestCase
{
    private PaymentSession $session;

    private RoutingRule $rule;

    protected function setUp(): void
    {
        parent::setUp();

        // The real migrations are MySQL-only; just the columns checkout touches.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_type', 100);
            $table->string('entity_type', 50);
            $table->string('entity_id');
            $table->string('actor_type', 30);
            $table->json('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('trace_id', 100);
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('pms_client_id')->nullable();
            $table->string('pms_source')->nullable();
            $table->string('external_invoice_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->integer('amount_cents');
            $table->string('currency', 3);
            $table->string('fund_type');
            $table->string('status');
            $table->string('pms_sync_status')->nullable();
            $table->json('raw_payload')->nullable();
            $table->json('recipient_emails')->nullable();
            $table->string('success_redirect_url')->nullable();
            $table->string('cancel_redirect_url')->nullable();
            $table->timestamps();
        });
        Schema::create('payment_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
            $table->string('hosted_url_token');
            $table->string('status');
            $table->string('link_status')->default('active');
            $table->string('fund_type');
            $table->string('payment_method')->nullable();
            $table->string('idempotency_key');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('action');
            $table->string('response_status');
            $table->uuid('payment_session_id');
            $table->uuid('transaction_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('routing_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('merchant_id');
            $table->string('gateway');
            $table->string('mid');
            $table->string('payment_method');
            $table->string('fund_type');
            $table->integer('min_amount_cents')->default(0);
            $table->integer('max_amount_cents')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_fallback')->default(false);
            $table->integer('priority')->default(1);
            $table->text('mid_credentials')->nullable();
            $table->timestamps();
        });

        $invoice = Invoice::query()->forceCreate([
            'external_invoice_id' => 'INV-1001',
            'amount_cents'        => 25000,
            'currency'            => 'USD',
            'fund_type'           => 'OPERATING',
            'status'              => 'PENDING',
        ]);

        $this->session = PaymentSession::query()->forceCreate([
            'invoice_id'       => $invoice->id,
            'hosted_url_token' => Str::random(40),
            'status'           => 'AWAITING_PAYMENT',
            'fund_type'        => 'OPERATING',
            'idempotency_key'  => 'idem-'.Str::random(8),
        ]);

        IdempotencyKey::query()->forceCreate([
            'key'                => $this->session->idempotency_key,
            'action'             => 'charge',
            'response_status'    => 'PENDING',
            'payment_session_id' => $this->session->id,
            'expires_at'         => now()->addDay(),
        ]);

        // FluidPay rule with no API key — a misconfigured gateway.
        $this->rule = RoutingRule::query()->forceCreate([
            'merchant_id'     => 'default',
            'gateway'         => 'fluidpay',
            'mid'             => 'MID-TEST',
            'payment_method'  => 'CARD',
            'fund_type'       => 'ANY',
            'mid_credentials' => ['environment' => 'sandbox'],
        ]);
    }

    private function submit(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/payment/sessions/{$this->session->hosted_url_token}/submit", [
            'token'           => 'tok_test',
            'routing_rule_id' => $this->rule->id,
            'payment_method'  => 'CARD',
            'first_name'      => 'Pat',
            'last_name'       => 'Payer',
            'billing_address' => ['address1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701'],
        ], ['X-Checkout-Visit' => (string) Str::uuid()]);
    }

    private function events(): array
    {
        return AuditLog::query()->orderBy('id')->pluck('event_type')->all();
    }

    public function test_an_attempt_on_a_misconfigured_gateway_is_on_record(): void
    {
        $this->submit()
            ->assertStatus(422)
            ->assertJsonPath('message', PaymentCheckoutService::UNAVAILABLE_MESSAGE);

        $this->assertSame(['PAYMENT_SUBMITTED', 'GATEWAY_MISCONFIGURED', 'PAYMENT_ATTEMPT_FAILED'], $this->events());

        $misconfigured = AuditLog::query()->where('event_type', 'GATEWAY_MISCONFIGURED')->first();
        $this->assertSame('credentials_missing', $misconfigured->payload['reason']);
        $this->assertSame(['api_key'], $misconfigured->payload['missing_keys']);
        $this->assertSame('fluidpay', $misconfigured->payload['gateway']);

        $failed = AuditLog::query()->where('event_type', 'PAYMENT_ATTEMPT_FAILED')->first();
        $this->assertSame('payer', $failed->actor_type);
        $this->assertSame($this->session->invoice_id, $failed->payload['invoice_id']);
        $this->assertSame(PaymentCheckoutService::UNAVAILABLE_MESSAGE, $failed->payload['message']);

        // One request, one trace.
        $this->assertSame(1, AuditLog::query()->distinct()->count('trace_id'));
    }

    public function test_a_misconfigured_gateway_does_not_lock_the_session(): void
    {
        $this->submit()->assertStatus(422);

        $this->assertNotSame('PROCESSING', $this->session->fresh()->status);
        $this->assertNotSame('IN_FLIGHT', IdempotencyKey::query()->where('key', $this->session->idempotency_key)->value('response_status'));

        // Retrying reports the real problem, not "already being processed".
        $this->submit()
            ->assertStatus(422)
            ->assertJsonPath('message', PaymentCheckoutService::UNAVAILABLE_MESSAGE);
    }

    public function test_a_rejected_submission_is_on_record(): void
    {
        $this->postJson("/api/v1/payment/sessions/{$this->session->hosted_url_token}/submit", [
            'routing_rule_id' => $this->rule->id,
        ])->assertStatus(422);

        $this->assertSame(['PAYMENT_SUBMITTED', 'PAYMENT_ATTEMPT_FAILED'], $this->events());

        $failed = AuditLog::query()->where('event_type', 'PAYMENT_ATTEMPT_FAILED')->first();
        $this->assertSame('validation', $failed->payload['stage']);
        $this->assertContains('token', $failed->payload['invalid_fields']);
    }

    public function test_browser_steps_are_recorded_with_only_allowed_detail(): void
    {
        $visitId = (string) Str::uuid();

        $this->postJson("/api/v1/payment/sessions/{$this->session->hosted_url_token}/events", [
            'event'    => 'card_tokenize_failed',
            'visit_id' => $visitId,
            'detail'   => [
                'gateway'     => 'fluidpay',
                'status'      => 'validation',
                'card_number' => '4111111111111111',
            ],
        ])->assertNoContent();

        $log = AuditLog::query()->sole();
        $this->assertSame('CHECKOUT_CARD_TOKENIZE_FAILED', $log->event_type);
        $this->assertSame($visitId, $log->payload['visit_id']);
        $this->assertSame('browser', $log->payload['source']);
        $this->assertSame('validation', $log->payload['status']);
        $this->assertArrayNotHasKey('card_number', $log->payload);
    }

    public function test_unknown_browser_events_are_ignored(): void
    {
        $this->postJson("/api/v1/payment/sessions/{$this->session->hosted_url_token}/events", [
            'event' => 'anything_goes',
        ])->assertNoContent();

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_no_payment_options_records_why(): void
    {
        $this->rule->forceFill(['is_active' => false])->save();

        $this->getJson("/api/v1/payment/sessions/{$this->session->hosted_url_token}")->assertStatus(422);

        $this->assertSame(['GATEWAY_MISCONFIGURED', 'CHECKOUT_LOAD_FAILED'], $this->events());
        $this->assertSame('no_payment_options', AuditLog::query()->first()->payload['reason']);
    }
}
