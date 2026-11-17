<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Models\Transaction;
use Modules\Billing\Services\PaymentSessionService;
use Modules\Inbound\Mail\LawcusRecordPaymentNeededMail;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\LawcusConnection;
use Modules\Inbound\Services\LawcusPaymentSyncService;
use Modules\Routing\Models\RoutingRule;
use Tests\TestCase;

/**
 * POST /accounts/transactions isn't part of Lawcus's published API, so each recording is
 * confirmed by the invoice's amount due, and the firm is asked to record any payment that
 * isn't confirmed by hand.
 */
class LawcusRecordPaymentAlertTest extends TestCase
{
    use RefreshDatabase;

    private const API         = 'https://api.us.lawcus.com';
    private const MATTER_UUID = '34b58a90-b8be-11f1-9433-6583f0484da9';

    private Client $client;

    private Invoice $invoice;

    private string $amountDue = '100.00';

    private array $payments = [];

    private int $posts = 0;

    /** How the fake Lawcus answers the payment POST: 'ok', 'error', 'ignored' (200, nothing recorded) or 'timeout-but-recorded'. */
    private string $postBehavior = 'ok';

    /** How the Invoice History report answers: 'live', 'stale', 'missing' (not listed) or 'error'. */
    private string $report = 'live';

    private int $reportReads = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config()->set('services.lawcus.rest_api_base_url', self::API);
        config()->set('services.lawcus.ops_alert_email', 'ops@example.com');

        $this->client = Client::query()->forceCreate([
            'id'                             => (string) Str::uuid(),
            'pms_client_id'                  => (string) Str::uuid(),
            'client_name'                    => 'RR Law',
            'client_pms'                     => 'LAWCUS',
            'payment_link_admin_email'       => 'firm@example.com',
            'lawcus_default_bank_account_id' => 'acct-operating',
            'lawcus_trust_bank_account_id'   => 'acct-trust',
        ]);

        LawcusConnection::query()->create([
            'provider'      => 'lawcus',
            'pms_client_id' => $this->client->pms_client_id,
            'access_token'  => 'api-token',
        ]);

        $this->invoice = $this->lawcusInvoice('OPERATING');

        Http::fake(function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/reports/invoice-history') {
                $this->reportReads++;

                return match ($this->report) {
                    'error'   => Http::response(['message' => 'Server error'], 500),
                    'missing' => Http::response(['list' => []]),
                    // A stale report still shows the full amount due.
                    'stale'   => Http::response(['list' => ['9' => [['id' => 'inv-1', 'number' => '00040', 'amount_due' => '100.00']]]]),
                    default   => Http::response(['list' => ['9' => [['id' => 'inv-1', 'number' => '00040', 'amount_due' => $this->amountDue]]]]),
                };
            }

            if (str_starts_with($path, '/invoices/')) {
                return Http::response(['invoice' => ['id' => 'inv-1', 'client_id' => 9, 'amount_due' => $this->amountDue], 'payments' => $this->payments]);
            }

            if ($path === '/accounts/transactions') {
                $this->posts++;
                $payment = $request['payments'][0];

                if (in_array($this->postBehavior, ['ok', 'timeout-but-recorded'], true)) {
                    $this->payments[] = ['note' => $payment['note'], 'amount' => $payment['amount']];
                    $this->amountDue  = number_format((float) $this->amountDue - (float) $payment['amount'], 2, '.', '');
                }

                return match ($this->postBehavior) {
                    'error'                => Http::response(['message' => 'Unknown field'], 422),
                    'timeout-but-recorded' => throw new ConnectionException('cURL error 28: timed out'),
                    default                => Http::response([]),
                };
            }

            return Http::response(['id' => 1]);
        });
    }

    public function test_a_confirmed_payment_sends_no_alert(): void
    {
        $this->assertTrue($this->sync());

        $this->assertSame('SYNCED', $this->invoice->fresh()->pms_sync_status);
        $this->assertSame(1, $this->reportReads, 'confirmed through the Invoice History report');
        Mail::assertNothingSent();
    }

    public function test_the_report_not_showing_the_drop_is_alerted(): void
    {
        $this->report = 'stale';

        $this->assertFalse($this->sync());

        Mail::assertSent(LawcusRecordPaymentNeededMail::class, 1);
    }

    public function test_an_unreadable_report_falls_back_to_the_invoice(): void
    {
        $this->report = 'error';

        $this->assertTrue($this->sync());

        Mail::assertNothingSent();
    }

    public function test_an_invoice_the_report_does_not_list_falls_back_to_the_invoice(): void
    {
        // Trust Requests aren't in the Invoice History report.
        $this->invoice->forceFill(['fund_type' => 'TRUST'])->save();
        $this->report = 'missing';

        $this->assertTrue($this->sync('ACH'));

        Mail::assertNothingSent();
    }

    public function test_a_failed_post_asks_the_firm_and_admin_to_record_it(): void
    {
        $this->postBehavior = 'error';

        $this->assertFalse($this->sync());

        $this->assertSame('FAILED', $this->invoice->fresh()->pms_sync_status);
        Mail::assertSent(LawcusRecordPaymentNeededMail::class, function (LawcusRecordPaymentNeededMail $mail) {
            $html = $mail->render();

            return $mail->hasTo('firm@example.com')
                && $mail->hasTo('ops@example.com')
                && $mail->envelope()->subject === 'Action needed: record payment for Invoice #00040'
                && str_contains($html, "We received a payment but couldn't record it in Lawcus automatically.")
                && str_contains($html, 'Open invoice in Lawcus')
                && str_contains($html, 'https://app.lawcus.com/invoices/inv-1')
                && str_contains($html, 'Invoice #00040 (Pat Payer, Smith v. Jones)')
                && str_contains($html, 'Direct client payment')
                && str_contains($html, 'Operating')
                && str_contains($html, '$100.00')
                && str_contains($html, 'SimpHub transaction txn-1, card');
        });
    }

    public function test_an_accepted_post_whose_amount_due_does_not_drop_is_alerted(): void
    {
        $this->postBehavior = 'ignored';

        $this->assertFalse($this->sync());

        Mail::assertSent(LawcusRecordPaymentNeededMail::class, 1);
    }

    public function test_a_timed_out_post_that_landed_anyway_is_not_alerted(): void
    {
        $this->postBehavior = 'timeout-but-recorded';

        $this->assertTrue($this->sync());

        Mail::assertNothingSent();
    }

    public function test_the_alert_goes_out_once_across_retries(): void
    {
        $this->postBehavior = 'error';

        $this->sync();
        $this->sync();

        Mail::assertSent(LawcusRecordPaymentNeededMail::class, 1);
    }

    public function test_a_trust_payment_names_the_trust_account_and_method(): void
    {
        $this->invoice->forceFill(['fund_type' => 'TRUST'])->save();
        $this->postBehavior = 'error';

        $this->sync('ACH');

        Mail::assertSent(LawcusRecordPaymentNeededMail::class, function (LawcusRecordPaymentNeededMail $mail) {
            $html = $mail->render();

            return str_contains($html, 'Trust (IOLTA)') && str_contains($html, 'SimpHub transaction txn-1, ACH');
        });
    }

    public function test_a_payment_the_firm_recorded_from_the_alert_is_not_recorded_again(): void
    {
        $this->payments  = [['note' => 'SimpHub transaction txn-1, card', 'amount' => '100.00']];
        $this->amountDue = '0.00';

        $this->assertTrue($this->sync());

        $this->assertSame(0, $this->posts);
        Mail::assertNothingSent();
    }

    public function test_an_invoice_with_nothing_left_to_pay_is_not_alerted(): void
    {
        // Could already be recorded without our reference — asking again could double it.
        $this->amountDue = '0.00';

        $this->assertFalse($this->sync());

        Mail::assertNothingSent();
    }

    /**
     * A $1,000 invoice charged $1,035 with the card fee records $1,000 — whether the fee
     * was stored, wasn't stored, or was stored wrong. Booking the fee on the invoice left
     * an overpayment in QBO.
     *
     * @return array<string, array{0: ?int}>
     */
    public static function feeCases(): array
    {
        return ['fee stored' => [3500], 'fee not stored' => [0], 'fee stored too low' => [1000]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('feeCases')]
    public function test_the_invoice_amount_is_recorded_never_the_card_fee(?int $feeCents): void
    {
        $this->invoice->forceFill(['amount_cents' => 100000])->save();
        $this->amountDue = '1000.00';

        $this->assertTrue($this->sync('CARD', 103500, $feeCents));

        $this->assertSame([1000.0], array_map(fn ($p) => (float) $p['amount'], $this->payments));
        $this->assertSame('0.00', $this->amountDue);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('feeCases')]
    public function test_the_manual_alert_asks_for_the_invoice_amount_not_the_charge(?int $feeCents): void
    {
        $this->invoice->forceFill(['amount_cents' => 100000])->save();
        $this->amountDue    = '1000.00';
        $this->postBehavior = 'error';

        $this->sync('CARD', 103500, $feeCents);

        Mail::assertSent(LawcusRecordPaymentNeededMail::class, function (LawcusRecordPaymentNeededMail $mail) {
            $html = $mail->render();

            return str_contains($html, '$1,000.00') && ! str_contains($html, '1,035');
        });
    }

    public function test_a_missing_connection_is_alerted(): void
    {
        LawcusConnection::query()->delete();

        $this->assertFalse($this->sync());

        Mail::assertSent(LawcusRecordPaymentNeededMail::class, 1);
    }

    private function sync(string $method = 'CARD', int $chargedCents = 10000, ?int $feeCents = 0): bool
    {
        $session = PaymentSession::query()->where('invoice_id', $this->invoice->id)->first()
            ?? app(PaymentSessionService::class)->create($this->invoice);
        $session->forceFill(['payment_method' => $method])->save();

        $rule = RoutingRule::query()->firstOrCreate(['mid' => 'MID-TEST'], [
            'merchant_id' => 'default', 'gateway' => 'nmi', 'payment_method' => 'ANY', 'fund_type' => 'ANY',
            'mid_credentials' => ['environment' => 'sandbox'],
        ]);

        $transaction = Transaction::query()->firstOrCreate(['gateway_txn_id' => 'txn-1'], [
            'payment_session_id' => $session->id,
            'routing_rule_id'    => $rule->id,
            'invoice_id'         => $this->invoice->id,
            'gateway'            => 'nmi',
            'mid'                => 'MID-TEST',
            'gateway_token'      => '',
            'status'             => 'CAPTURED',
            'transaction_type'   => 'debit',
            'fund_type'          => $this->invoice->fund_type,
            'amount_cents'       => $chargedCents,
            'fee_cents'          => $feeCents,
            'currency'           => 'USD',
        ]);

        return app(LawcusPaymentSyncService::class)->sync($transaction, $this->invoice);
    }

    private function lawcusInvoice(string $fundType): Invoice
    {
        return Invoice::query()->forceCreate([
            'pms_source'          => 'lawcus',
            'pms_client_id'       => $this->client->pms_client_id,
            'external_invoice_id' => 'inv-1',
            'external_client_id'  => '9',
            'external_matter_id'  => '1949408',
            'invoice_number'      => '00040',
            'status'              => 'PAID',
            'fund_type'           => $fundType,
            'amount_cents'        => 10000,
            'currency'            => 'USD',
            'customer'            => ['name' => 'Pat Payer', 'email' => 'client@example.com'],
            'recipient_emails'    => ['client@example.com'],
            'raw_payload'         => ['invoice' => ['data' => [
                'id'          => 'inv-1',
                'client_id'   => 9,
                'matter_uuid' => self::MATTER_UUID,
                'matter'      => ['name' => 'Smith v. Jones'],
            ]]],
        ]);
    }
}
