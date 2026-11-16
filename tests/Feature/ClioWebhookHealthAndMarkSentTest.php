<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Jobs\MarkClioBillSentJob;
use Modules\Inbound\Jobs\RenewClioWebhooksJob;
use Modules\Inbound\Models\ClioConnection;
use Modules\Inbound\Services\ClioApiClient;
use Modules\Inbound\Services\ClioOAuthService;
use Modules\Inbound\Services\ClioWebhookService;
use Tests\TestCase;

/** Clio webhook health checks (expiry + suspension) and marking bills as sent after our email. */
class ClioWebhookHealthAndMarkSentTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://app.clio.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.clio.api_base_url'         => self::API,
            'services.clio.webhook_callback_url' => 'https://simphub.test/api/v1/inbound/webhooks/clio',
        ]);
    }

    private function connection(array $attributes = []): ClioConnection
    {
        return ClioConnection::query()->create(array_merge([
            'provider'      => 'clio',
            'pms_client_id' => 'firm-1',
            'access_token'  => 'token',
            'webhook_id'    => '55',
        ], $attributes));
    }

    // --- Defaults -------------------------------------------------------------------------

    public function test_approval_state_default_matches_clio_and_state_is_not_subscribed(): void
    {
        $config = require base_path('config/services.php');

        $this->assertContains('AWAITING_PAYMENT', $config['clio']['approved_bill_states']);
        $this->assertNotContains('state', $config['clio']['webhook_fields']);
        $this->assertContains('updated_at', $config['clio']['webhook_fields']);
    }

    // --- Webhook health -------------------------------------------------------------------

    public function test_a_suspended_webhook_is_deleted_and_replaced(): void
    {
        Http::fake([
            self::API.'/api/v4/webhooks/55.json*' => function (Request $request) {
                return $request->method() === 'DELETE'
                    ? Http::response('', 204)
                    : Http::response(['data' => ['id' => 55, 'status' => 'suspended', 'expires_at' => now()->addDays(20)->toIso8601String()]]);
            },
            self::API.'/api/v4/webhooks' => Http::response(['data' => ['id' => 77, 'expires_at' => now()->addDays(30)->toIso8601String()]], 201),
        ]);

        $connection = $this->connection();
        $result     = app(ClioWebhookService::class)->maintainWebhook($connection, CarbonImmutable::now()->addDays(7));

        $this->assertSame(ClioWebhookService::WEBHOOK_SUSPENDED, $result['action']);
        $this->assertSame('77', (string) $connection->fresh()->webhook_id);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/webhooks/55.json'));
    }

    public function test_a_webhook_clio_no_longer_has_is_registered_again(): void
    {
        Http::fake([
            self::API.'/api/v4/webhooks/55.json*' => Http::response(['error' => ['message' => 'Not found']], 404),
            self::API.'/api/v4/webhooks'          => Http::response(['data' => ['id' => 78]], 201),
        ]);

        $connection = $this->connection();
        $result     = app(ClioWebhookService::class)->maintainWebhook($connection, CarbonImmutable::now()->addDays(7));

        $this->assertSame(ClioWebhookService::WEBHOOK_MISSING, $result['action']);
        $this->assertSame('78', (string) $connection->fresh()->webhook_id);
    }

    public function test_a_healthy_webhook_far_from_expiry_is_left_alone(): void
    {
        $expires = now()->addDays(20)->toIso8601String();

        Http::fake([
            self::API.'/api/v4/webhooks/55.json*' => Http::response(['data' => ['id' => 55, 'status' => 'enabled', 'expires_at' => $expires]]),
        ]);

        $connection = $this->connection(['last_error' => 'Webhook renewal failed: earlier outage']);
        $result     = app(ClioWebhookService::class)->maintainWebhook($connection, CarbonImmutable::now()->addDays(7));

        $this->assertSame(ClioWebhookService::WEBHOOK_OK, $result['action']);
        $this->assertNull($connection->fresh()->last_error);
        Http::assertSentCount(1);
    }

    public function test_a_healthy_webhook_close_to_expiry_is_renewed(): void
    {
        Http::fake([
            self::API.'/api/v4/webhooks/55.json*' => function (Request $request) {
                return $request->method() === 'PATCH'
                    ? Http::response(['data' => ['id' => 55, 'expires_at' => now()->addDays(30)->toIso8601String()]])
                    : Http::response(['data' => ['id' => 55, 'status' => 'enabled', 'expires_at' => now()->addDays(2)->toIso8601String()]]);
            },
        ]);

        $result = app(ClioWebhookService::class)->maintainWebhook($this->connection(), CarbonImmutable::now()->addDays(7));

        $this->assertSame(ClioWebhookService::WEBHOOK_RENEWED, $result['action']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_only_a_stopped_webhook_raises_an_alert(): void
    {
        $this->assertTrue(RenewClioWebhooksJob::needsAlert(ClioWebhookService::WEBHOOK_SUSPENDED));
        $this->assertTrue(RenewClioWebhooksJob::needsAlert(ClioWebhookService::WEBHOOK_MISSING));
        $this->assertTrue(RenewClioWebhooksJob::needsAlert(ClioWebhookService::WEBHOOK_REGISTERED));
        $this->assertFalse(RenewClioWebhooksJob::needsAlert(ClioWebhookService::WEBHOOK_OK));
        $this->assertFalse(RenewClioWebhooksJob::needsAlert(ClioWebhookService::WEBHOOK_RENEWED));
    }

    // --- mark_as_sent ---------------------------------------------------------------------

    public function test_mark_as_sent_uses_the_documented_query_parameter(): void
    {
        Http::fake([self::API.'/*' => Http::response('', 200)]);

        app(ClioApiClient::class)->markBillSent((new ClioConnection)->forceFill(['access_token' => 'token']), '901');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === self::API.'/api/v4/bills/901/mark_as_sent.json?sent_method=manual_email');
    }

    public function test_mark_sent_counts_only_when_the_client_got_the_email(): void
    {
        $this->assertTrue(MarkClioBillSentJob::reachedClient(['Client@Example.com'], ['client@example.com', 'admin@firm.test']));
        $this->assertFalse(MarkClioBillSentJob::reachedClient(['client@example.com'], ['admin@firm.test']));
        $this->assertFalse(MarkClioBillSentJob::reachedClient(['client@example.com'], []));
    }

    public function test_bill_is_marked_sent_in_clio_after_the_client_is_emailed(): void
    {
        Http::fake([self::API.'/*' => Http::response('', 200)]);

        $this->runMarkSentJob(['client@example.com']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/v4/bills/901/mark_as_sent.json'));
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'CLIO_BILL_MARKED_SENT']);
    }

    public function test_bill_is_not_marked_sent_when_only_the_firm_admin_got_it(): void
    {
        Http::fake();

        $this->runMarkSentJob(['admin@firm.test']);

        Http::assertNothingSent();
    }

    public function test_other_pms_bills_are_never_marked(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        MarkClioBillSentJob::dispatchFor((new Invoice)->forceFill(['id' => (string) Str::uuid(), 'pms_source' => 'lawcus']));
        config(['services.clio.mark_bills_sent' => false]);
        MarkClioBillSentJob::dispatchFor((new Invoice)->forceFill(['id' => (string) Str::uuid(), 'pms_source' => 'clio']));

        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    private function runMarkSentJob(array $sentTo): void
    {
        $connection = $this->connection();

        $invoice = Invoice::query()->forceCreate([
            'pms_source'          => 'clio',
            'pms_client_id'       => 'firm-1',
            'external_invoice_id' => '901',
            'external_client_id'  => 'contact-1',
            'amount_cents'        => 50000,
            'currency'            => 'USD',
            'fund_type'           => 'OPERATING',
            'status'              => 'AWAITING_PAYMENT',
            'recipient_emails'    => ['client@example.com'],
        ]);

        PaymentSession::query()->forceCreate([
            'invoice_id'                => $invoice->id,
            'hosted_url_token'          => Str::random(40),
            'status'                    => 'AWAITING_PAYMENT',
            'fund_type'                 => 'OPERATING',
            'idempotency_key'           => 'idem-'.Str::random(8),
            'link_status'               => 'active',
            'expires_at'                => now()->addDays(30),
            'payment_link_sent_at'      => now(),
            'payment_link_last_sent_to' => $sentTo,
        ]);

        $oauth = Mockery::mock(ClioOAuthService::class);
        $oauth->shouldReceive('ensureValidAccessToken')->andReturn($connection);

        (new MarkClioBillSentJob((string) $invoice->id))->handle(app(ClioApiClient::class), $oauth);
    }
}
