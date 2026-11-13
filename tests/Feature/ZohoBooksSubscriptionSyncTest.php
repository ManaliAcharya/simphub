<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Jobs\SyncZohoBooksPaymentJob;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Models\Subscription;
use Modules\Inbound\Services\ZohoBooksSubscriptionSyncService;
use Modules\Inbound\Services\ZohoCrmPaymentSyncService;
use Modules\Payment\Listeners\SyncInvoicePaidListener;
use RuntimeException;
use Tests\TestCase;

/**
 * A paid Zoho CRM subscription period becomes a paid invoice in Zoho Books: $199.00
 * charged $205.97 (3.5% fee), so Books gets $199.00. Retries resume from the last saved
 * step and never create a second invoice or payment.
 */
class ZohoBooksSubscriptionSyncTest extends TestCase
{
    private const BOOKS = '/books/v3';

    private const PMS_CLIENT_ID = '7a1c0c52-4a2e-4f7e-9d61-0f3b2a7c9e11';

    /** @var array<string, mixed> "METHOD /path" => response body, or a closure returning a response */
    private array $routes = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-03 15:00:00');

        // The real migrations are MySQL-only; just the columns the Books sync touches.
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

        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pms_client_id');
            $table->string('client_name')->nullable();
            $table->string('client_pms')->nullable();
            $table->string('zoho_region')->nullable();
            $table->boolean('call_api_to_pms')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pms_connections', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->uuid('pms_client_id');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('meta')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('reference_id')->nullable();
            $table->uuid('pms_client_id');
            $table->string('customer_email');
            $table->string('customer_name')->nullable();
            $table->string('customer_unique_id')->nullable();
            $table->string('zoho_books_customer_id')->nullable();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('pms_source')->nullable();
            $table->string('pms_client_id')->nullable();
            $table->string('external_invoice_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->integer('amount_cents')->default(0);
            $table->string('currency')->nullable();
            $table->string('status')->nullable();
            $table->string('description')->nullable();
            $table->string('pms_sync_status')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id')->nullable();
            $table->string('gateway')->nullable();
            $table->string('status')->nullable();
            $table->integer('amount_cents')->default(0);
            $table->integer('fee_cents')->nullable();
            $table->string('gateway_txn_id')->nullable();
            $table->string('fee_sync_status', 20)->nullable();
            $table->timestamps();
        });

        Client::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'pms_client_id' => self::PMS_CLIENT_ID,
            'client_name' => 'Acme',
            'client_pms' => 'ZOHOCRM',
        ]);

        Http::fake(function (Request $request) {
            $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen(self::BOOKS));
            $route = $this->routes[$request->method().' '.$path] ?? null;

            if ($route instanceof \Closure) {
                return $route($request);
            }

            return $route === null
                ? Http::response(['code' => 1002, 'message' => 'Not faked: '.$request->method().' '.$path], 404)
                : Http::response($route);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_creates_customer_invoice_and_payment_without_the_fee(): void
    {
        $this->connection();
        [$invoice, $subscription, $transaction] = $this->paidPeriod();
        $this->routes = [
            'GET /contacts' => ['code' => 0, 'contacts' => []],
            'POST /contacts' => ['code' => 0, 'contact' => ['contact_id' => 'C1']],
            'GET /invoices' => ['code' => 0, 'invoices' => []],
            'POST /invoices' => ['code' => 0, 'invoice' => ['invoice_id' => 'I1', 'status' => 'draft']],
            'POST /invoices/I1/status/sent' => ['code' => 0, 'message' => 'Invoice status has been changed to Sent.'],
            'GET /invoices/I1' => ['code' => 0, 'invoice' => ['invoice_id' => 'I1', 'status' => 'sent', 'balance' => 199.00]],
            'POST /customerpayments' => ['code' => 0, 'payment' => ['payment_id' => 'P1']],
        ];

        $this->assertTrue($this->service()->sync($invoice, $transaction));

        $state = $invoice->fresh()->metadata['zoho_books'];
        $this->assertSame('synced', $state['status']);
        $this->assertSame(['C1', 'I1', 'P1'], [$state['customer_id'], $state['invoice_id'], $state['payment_id']]);
        $this->assertSame('C1', $subscription->fresh()->zoho_books_customer_id);

        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST'
                && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/invoices')
                && str_contains($r->url(), 'ignore_auto_number_generation=true')
                && str_contains($r->url(), 'organization_id=777')
                && $r['invoice_number'] === 'SH-SUB-000001-2026-11'
                && $r['customer_id'] === 'C1'
                && $r['line_items'][0]['rate'] === 199.0;
        });

        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST'
                && str_ends_with($r->url(), '/customerpayments')
                && $r['amount'] === 199.0
                && $r['invoices'][0] === ['invoice_id' => 'I1', 'amount_applied' => 199.0]
                && $r['reference_number'] === 'gw_48213377'
                && $r['account_id'] === 'acc-1'
                && $r['date'] === '2026-11-03';
        });
    }

    public function test_a_retry_resumes_at_the_payment_and_never_creates_a_second_invoice(): void
    {
        $this->connection();
        [$invoice, , $transaction] = $this->paidPeriod();
        $paymentCalls = 0;
        $this->routes = [
            'GET /contacts' => ['code' => 0, 'contacts' => [['contact_id' => 'C1', 'email' => 'JANE@example.com', 'contact_name' => 'Jane Doe']]],
            'GET /invoices' => ['code' => 0, 'invoices' => []],
            'POST /invoices' => ['code' => 0, 'invoice' => ['invoice_id' => 'I1', 'status' => 'draft']],
            'POST /invoices/I1/status/sent' => ['code' => 0],
            'GET /invoices/I1' => ['code' => 0, 'invoice' => ['invoice_id' => 'I1', 'status' => 'sent', 'balance' => 199.00]],
            'POST /customerpayments' => function () use (&$paymentCalls) {
                return ++$paymentCalls === 1
                    ? Http::response(['code' => 503, 'message' => 'Service Unavailable'], 503)
                    : Http::response(['code' => 0, 'payment' => ['payment_id' => 'P1']]);
            },
        ];

        try {
            $this->service()->sync($invoice, $transaction);
            $this->fail('The first try should fail on the payment call.');
        } catch (\Throwable) {
            $state = $invoice->fresh()->metadata['zoho_books'];
            $this->assertSame('failed', $state['status']);
            $this->assertSame('I1', $state['invoice_id']);
            $this->assertSame(1, $state['attempts']);
        }

        $this->assertTrue($this->service()->sync($invoice, $transaction));
        $this->assertSame('synced', $invoice->fresh()->metadata['zoho_books']['status']);

        $this->assertSame(1, $this->sentCount('POST', '/invoices'));
        $this->assertSame(1, $this->sentCount('GET', '/contacts'));
        $this->assertSame(2, $paymentCalls);
    }

    public function test_reuses_an_invoice_books_already_has_and_does_not_pay_it_twice(): void
    {
        $this->connection();
        [$invoice, , $transaction] = $this->paidPeriod();
        $this->routes = [
            'GET /contacts' => ['code' => 0, 'contacts' => [['contact_id' => 'C7', 'email' => 'jane@example.com']]],
            'GET /invoices' => ['code' => 0, 'invoices' => [['invoice_id' => 'I9', 'invoice_number' => 'SH-SUB-000001-2026-11', 'status' => 'paid']]],
            'GET /invoices/I9' => ['code' => 0, 'invoice' => ['invoice_id' => 'I9', 'status' => 'paid', 'balance' => 0, 'payments' => [['payment_id' => 'P9']]]],
        ];

        $this->assertTrue($this->service()->sync($invoice, $transaction));

        $state = $invoice->fresh()->metadata['zoho_books'];
        $this->assertSame(['I9', 'P9'], [$state['invoice_id'], $state['payment_id']]);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_does_nothing_when_books_sync_is_off(): void
    {
        $this->connection(enabled: false);
        [$invoice, , $transaction] = $this->paidPeriod();

        $this->assertFalse($this->service()->shouldSync($invoice));
        $this->assertFalse($this->service()->sync($invoice, $transaction));
        Http::assertNothingSent();
    }

    public function test_listener_queues_the_books_job_after_the_crm_update(): void
    {
        Queue::fake();
        $this->connection();
        [$invoice, , $transaction] = $this->paidPeriod();

        $crm = Mockery::mock(ZohoCrmPaymentSyncService::class);
        $crm->shouldReceive('sync')->once()->andReturn(false);
        $this->app->instance(ZohoCrmPaymentSyncService::class, $crm);

        $listener = app(SyncInvoicePaidListener::class);
        (fn () => $this->syncToZohoCrm($transaction, $invoice))->call($listener);

        Queue::assertPushed(SyncZohoBooksPaymentJob::class, fn ($job) => $job->invoiceId === $invoice->id
            && $job->transactionId === $transaction->id);
        $this->assertSame('queued', $invoice->fresh()->metadata['zoho_books']['status']);
    }

    public function test_settings_are_checked_against_books_and_an_org_change_clears_account_and_item(): void
    {
        $connection = $this->connection(enabled: false, books: []);
        $this->routes = [
            'GET /organizations' => ['code' => 0, 'organizations' => [
                ['organization_id' => '777', 'name' => 'Acme LLC'],
                ['organization_id' => '888', 'name' => 'Acme Holdings'],
            ]],
            'GET /chartofaccounts' => ['code' => 0, 'chartofaccounts' => [
                ['account_id' => 'acc-1', 'account_name' => 'Operating', 'account_type' => 'bank'],
                ['account_id' => 'acc-9', 'account_name' => 'Sales', 'account_type' => 'income'],
            ]],
            'GET /items' => ['code' => 0, 'items' => [['item_id' => 'it-1', 'name' => 'Monthly plan', 'rate' => 199]]],
        ];
        $service = $this->service();

        $this->assertThrows(fn () => $service->saveSettings($connection->fresh(), ['organization_id' => '999']), RuntimeException::class);
        $this->assertThrows(fn () => $service->saveSettings($connection->fresh(), ['enabled' => '1']), RuntimeException::class);

        $service->saveSettings($connection->fresh(), ['organization_id' => '777']);
        $this->assertSame(['acc-1'], array_column($service->options($connection->fresh())['accounts'], 'account_id'));

        $saved = $service->saveSettings($connection->fresh(), ['organization_id' => '777', 'account_id' => 'acc-1', 'item_id' => 'it-1', 'enabled' => '1']);
        $this->assertTrue($saved['enabled']);
        $this->assertSame(['acc-1', 'Monthly plan'], [$saved['account_id'], $saved['item_name']]);

        $moved = $service->saveSettings($connection->fresh(), ['organization_id' => '888', 'account_id' => 'acc-1', 'enabled' => '1']);
        $this->assertSame('Acme Holdings', $moved['organization_name']);
        $this->assertNull($moved['account_id']);
        $this->assertNull($moved['item_id']);
    }

    public function test_reconnecting_to_another_zoho_account_drops_the_old_organization(): void
    {
        $connection = $this->connection();
        [$invoice, $subscription] = $this->paidPeriod();
        $subscription->forceFill(['zoho_books_customer_id' => 'C-OLD'])->save();
        $invoice->forceFill(['metadata' => [...$invoice->metadata, 'zoho_books' => [
            'status' => 'failed', 'customer_id' => 'C-OLD', 'invoice_id' => 'I-OLD', 'attempts' => 2,
        ]]])->save();

        // The new login only sees its own organization; the saved 777 belongs to the old account.
        $this->routes = [
            'GET /organizations' => ['code' => 0, 'organizations' => [['organization_id' => '555', 'name' => 'ITL India']]],
        ];

        $options = $this->service()->options($connection->fresh());
        $this->assertSame(['555'], array_column($options['organizations'], 'organization_id'));
        $this->assertSame([], $options['accounts']);

        $this->service()->checkAccess($connection->fresh());

        $settings = $this->service()->settings($connection->fresh());
        $this->assertSame(['555', 'ITL India'], [$settings['organization_id'], $settings['organization_name']]);
        $this->assertNull($settings['account_id']);
        $this->assertNull($subscription->fresh()->zoho_books_customer_id);

        $state = $invoice->fresh()->metadata['zoho_books'];
        $this->assertSame('failed', $state['status']);
        $this->assertArrayNotHasKey('customer_id', $state);
        $this->assertArrayNotHasKey('invoice_id', $state);
    }

    private function service(): ZohoBooksSubscriptionSyncService
    {
        return app(ZohoBooksSubscriptionSyncService::class);
    }

    private function connection(bool $enabled = true, ?array $books = null): PmsConnection
    {
        return PmsConnection::query()->create([
            'provider' => 'zohocrm',
            'pms_client_id' => self::PMS_CLIENT_ID,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'token_expires_at' => now()->addHour(),
            'meta' => [
                'api_domain' => 'https://www.zohoapis.com',
                'books' => $books ?? [
                    'enabled' => $enabled,
                    'organization_id' => '777',
                    'organization_name' => 'Acme LLC',
                    'account_id' => 'acc-1',
                    'account_name' => 'Operating',
                ],
            ],
        ]);
    }

    /** @return array{0: Invoice, 1: Subscription, 2: Transaction} */
    private function paidPeriod(): array
    {
        $subscription = Subscription::query()->forceCreate([
            'reference_id' => 'SH-SUB-000001',
            'pms_client_id' => self::PMS_CLIENT_ID,
            'customer_email' => 'jane@example.com',
            'customer_name' => 'Jane Doe',
            'amount_cents' => 19900,
        ]);

        $invoice = Invoice::query()->create([
            'pms_source' => 'zohocrm',
            'pms_client_id' => self::PMS_CLIENT_ID,
            'external_invoice_id' => 'SH-SUB-000001-2026-11',
            'invoice_number' => 'SH-SUB-000001-2026-11',
            'amount_cents' => 19900,
            'currency' => 'USD',
            'status' => 'PAID',
            'description' => 'Subscription – November 2026',
            'pms_sync_status' => 'SYNCED',
            'metadata' => [
                'subscription_id' => $subscription->id,
                'subscription_reference_id' => 'SH-SUB-000001',
                'billing_period' => '2026-11',
            ],
        ]);

        $transaction = Transaction::query()->create([
            'invoice_id' => $invoice->id,
            'gateway' => 'fluidpay',
            'status' => 'APPROVED',
            'amount_cents' => 20597,
            'fee_cents' => 697,
            'gateway_txn_id' => 'gw_48213377',
        ]);

        return [$invoice, $subscription, $transaction];
    }

    private function sentCount(string $method, string $path): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === $method
            && (string) parse_url($r->url(), PHP_URL_PATH) === self::BOOKS.$path)->count();
    }
}
