<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\QuickBooksConnection;
use Modules\Inbound\Models\WaveConnection;
use Modules\Inbound\Services\QuickBooksApiClient;
use Modules\Inbound\Services\QuickBooksOAuthService;
use Modules\Inbound\Services\WaveApiClient;
use Modules\Inbound\Services\WaveOAuthService;
use Modules\Payment\Listeners\SyncInvoicePaidListener;
use Tests\TestCase;

/**
 * A PMS payment always settles the invoice at its face value, never the card total — on
 * dual pricing the processor keeps the fee, and booking it into the payment left a
 * QuickBooks overpayment / customer credit. The surcharge setting only decides whether the
 * fee is synced as well. Uses the $145.85 invoice charged $150.95 (3.5%) from the report.
 */
class PmsPaymentInvoiceAmountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The real migrations are MySQL-only; just the columns the payment sync touches.
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
            $table->string('pms_source')->nullable();
            $table->string('pms_client_id')->nullable();
            $table->string('external_invoice_id')->nullable();
            $table->string('external_client_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->integer('amount_cents')->default(0);
            $table->string('currency')->nullable();
            $table->string('status')->nullable();
            $table->string('pms_sync_status')->nullable();
            $table->json('raw_payload')->nullable();
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
            $table->string('qb_payment_id')->nullable();
            $table->string('qb_journalentry_id')->nullable();
            $table->timestamps();
        });

        foreach (array_unique([(new QuickBooksConnection)->getTable(), (new WaveConnection)->getTable()]) as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('provider')->nullable();
                $table->string('pms_client_id')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }
    }

    private function invoice(string $pms): Invoice
    {
        return Invoice::query()->create([
            'pms_source'          => $pms,
            'pms_client_id'       => 'client-1',
            'external_invoice_id' => '1001',
            'external_client_id'  => '55',
            'invoice_number'      => '1001',
            'amount_cents'        => 14585,
            'currency'            => 'usd',
        ]);
    }

    private function transaction(Invoice $invoice, ?int $feeCents = 510): Transaction
    {
        return Transaction::query()->create([
            'invoice_id'     => $invoice->id,
            'gateway'        => 'fluidpay',
            'status'         => 'CAPTURED',
            'amount_cents'   => 15095,
            'fee_cents'      => $feeCents,
            'gateway_txn_id' => 'txn-1',
        ]);
    }

    private function client(array $attributes = []): Client
    {
        return (new Client)->forceFill(array_merge(['pms_client_id' => 'client-1'], $attributes));
    }

    private function sync(string $method, Transaction $transaction, Invoice $invoice, Client $client): void
    {
        $listener = app(SyncInvoicePaidListener::class);

        (fn () => $this->{$method}($transaction, $invoice, $client))->call($listener);
    }

    private function mockQuickBooks(): Mockery\MockInterface
    {
        $connection = QuickBooksConnection::query()->create(['provider' => 'quickbooks', 'pms_client_id' => 'client-1']);

        $oauth = Mockery::mock(QuickBooksOAuthService::class);
        $oauth->shouldReceive('ensureValidAccessToken')->andReturn($connection);
        $this->app->instance(QuickBooksOAuthService::class, $oauth);

        $api = Mockery::mock(QuickBooksApiClient::class);
        $this->app->instance(QuickBooksApiClient::class, $api);

        return $api;
    }

    private function assertPaysInvoiceOnly(array $payload): void
    {
        $this->assertSame(145.85, $payload['TotalAmt']);
        $this->assertSame(145.85, $payload['Line'][0]['Amount']);
    }

    public function test_invoice_portion_never_includes_the_fee(): void
    {
        $invoice = (new Invoice)->forceFill(['amount_cents' => 14585]);
        $charge  = fn (int $amount, ?int $fee) => (new Transaction)->forceFill(['amount_cents' => $amount, 'fee_cents' => $fee]);

        $this->assertSame(145.85, SyncInvoicePaidListener::invoicePortion($charge(15095, 510), $invoice));
        // Fee not stored: derived from the overcharge.
        $this->assertSame(145.85, SyncInvoicePaidListener::invoicePortion($charge(15095, 0), $invoice));
        $this->assertSame(510, SyncInvoicePaidListener::feeCents($charge(15095, null), $invoice));
        // No fee charged (e.g. ACH): the charge is the invoice amount.
        $this->assertSame(145.85, SyncInvoicePaidListener::invoicePortion($charge(14585, 0), $invoice));
        // A partial payment is applied as paid, not topped up to the invoice amount.
        $this->assertSame(50.00, SyncInvoicePaidListener::invoicePortion($charge(5000, 0), $invoice));
    }

    public function test_quickbooks_with_surcharge_sync_off_records_the_invoice_amount_and_no_fee(): void
    {
        $api = $this->mockQuickBooks();
        $api->shouldReceive('recordPayment')->once()
            ->withArgs(function ($connection, array $payload) {
                $this->assertPaysInvoiceOnly($payload);

                return true;
            })
            ->andReturn(['Payment' => ['Id' => '901']]);
        $api->shouldNotReceive('recordJournalEntry');

        $invoice     = $this->invoice('quickbooks');
        $transaction = $this->transaction($invoice);

        $this->sync('syncToQuickBooks', $transaction, $invoice, $this->client(['qb_surcharge_enabled' => false]));

        $this->assertSame('SYNCED', $invoice->fresh()->pms_sync_status);
        $this->assertSame(Transaction::FEE_OFF, $transaction->fresh()->fee_sync_status);
    }

    public function test_quickbooks_with_surcharge_sync_on_records_the_invoice_amount_and_posts_the_fee_separately(): void
    {
        $api = $this->mockQuickBooks();
        $api->shouldReceive('recordPayment')->once()
            ->withArgs(function ($connection, array $payload) {
                $this->assertPaysInvoiceOnly($payload);

                return true;
            })
            ->andReturn(['Payment' => ['Id' => '901']]);
        $api->shouldReceive('recordJournalEntry')->once()
            ->withArgs(function ($connection, array $payload) {
                $this->assertSame(5.10, $payload['Line'][0]['Amount']);
                $this->assertSame('81', $payload['Line'][1]['JournalEntryLineDetail']['AccountRef']['value']);

                return true;
            })
            ->andReturn(['JournalEntry' => ['Id' => '902']]);

        $invoice     = $this->invoice('quickbooks');
        $transaction = $this->transaction($invoice);

        $this->sync('syncToQuickBooks', $transaction, $invoice, $this->client([
            'qb_surcharge_enabled'    => true,
            'qb_surcharge_account_id' => '81',
            'qb_default_account_id'   => '35',
        ]));

        $this->assertSame(Transaction::FEE_SPLIT, $transaction->fresh()->fee_sync_status);
    }

    public function test_quickbooks_with_surcharge_sync_on_but_no_income_account_still_records_only_the_invoice_amount(): void
    {
        $api = $this->mockQuickBooks();
        $api->shouldReceive('recordPayment')->once()
            ->withArgs(function ($connection, array $payload) {
                $this->assertPaysInvoiceOnly($payload);

                return true;
            })
            ->andReturn(['Payment' => ['Id' => '901']]);

        $invoice     = $this->invoice('quickbooks');
        $transaction = $this->transaction($invoice, feeCents: 0);

        $this->sync('syncToQuickBooks', $transaction, $invoice, $this->client(['qb_surcharge_enabled' => true]));

        // Flagged for fixing: fee sync was wanted but there was nowhere to post it.
        $this->assertSame(Transaction::FEE_NOT_RECORDED, $transaction->fresh()->fee_sync_status);
    }

    public function test_wave_with_surcharge_sync_off_records_the_invoice_amount_and_no_fee(): void
    {
        $connection = WaveConnection::query()->create(['provider' => 'wave', 'pms_client_id' => 'client-1']);

        $oauth = Mockery::mock(WaveOAuthService::class);
        $oauth->shouldReceive('ensureValidAccessToken')->andReturn($connection);
        $this->app->instance(WaveOAuthService::class, $oauth);

        $api = Mockery::mock(WaveApiClient::class);
        $api->shouldReceive('recordInvoicePayment')->once()
            ->withArgs(fn ($connection, $relayId, float $amount) => $amount === 145.85);
        $api->shouldNotReceive('recordSurchargeIncome');
        $this->app->instance(WaveApiClient::class, $api);

        $invoice     = $this->invoice('wave');
        $transaction = $this->transaction($invoice);

        $this->sync('syncToWave', $transaction, $invoice, $this->client(['wave_surcharge_enabled' => false]));

        $this->assertSame('SYNCED', $invoice->fresh()->pms_sync_status);
        $this->assertSame(Transaction::FEE_OFF, $transaction->fresh()->fee_sync_status);
    }
}
