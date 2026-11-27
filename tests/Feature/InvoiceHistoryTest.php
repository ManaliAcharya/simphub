<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Audit\Models\AuditLog;
use Modules\Auth\Http\Middleware\MerchantSessionMiddleware;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Models\Transaction;
use Tests\TestCase;

/**
 * The "History" button on the invoice list: a plain-English timeline of one invoice.
 */
class InvoiceHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = 'e944ba76-0000-4000-8000-000000000001';

    private Invoice $invoice;

    private PaymentSession $session;

    private int $minute = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(MerchantSessionMiddleware::class);

        $this->invoice = Invoice::query()->forceCreate([
            'pms_source'          => 'quickbooks',
            'pms_client_id'       => self::CLIENT,
            'external_invoice_id' => '1199',
            'external_client_id'  => '191',
            'invoice_number'      => '1008',
            'amount_cents'        => 70063,
            'currency'            => 'USD',
            'fund_type'           => 'OPERATING',
            'status'              => 'SENT',
        ]);

        $this->session = PaymentSession::query()->forceCreate([
            'invoice_id'       => $this->invoice->id,
            'hosted_url_token' => Str::random(40),
            'status'           => 'PENDING',
            'fund_type'        => 'OPERATING',
            'idempotency_key'  => 'idem-'.Str::random(8),
            'link_status'      => 'active',
            'expires_at'       => now()->addDays(30),
        ]);
    }

    public function test_it_lists_what_happened_in_plain_words_newest_first(): void
    {
        $this->log('INVOICE_RECEIVED', 'invoice', $this->invoice->id, ['pms_source' => 'quickbooks']);
        $this->log('ROUTING_DECISION_MADE', 'payment_session', $this->session->id);
        $this->log('PAYMENT_LINK_SENT', 'payment_session', $this->session->id, ['recipient_emails' => ['payer@example.com']]);
        $this->log('payment_reminder_sent', 'payment_session', $this->session->id, ['reminder_number' => 1]);
        $this->log('payment_link.marked_paid_via_qb_payment', 'payment_session', $this->session->id);
        $this->log('payment_link.reopened', 'payment_session', $this->session->id, ['previous_reason' => 'paid_in_pms']);
        $this->log('PAYMENT_LINK_RESENT', 'payment_session', $this->session->id, ['recipient_emails' => ['payer@example.com']], 'merchant');

        $entries = $this->history()->assertOk()->assertJsonPath('invoice', '#1008')->json('entries');

        $this->assertSame([
            'Payment link resent',
            'Payment link reopened',
            'Payment link closed: paid in QuickBooks',
            'Reminder #1 emailed',
            'Payment link emailed',
            'Invoice received from QuickBooks',
        ], array_column($entries, 'title'), 'Internal routing steps are left out.');

        $this->assertSame('Your team', $entries[0]['who']);
        $this->assertSame('To payer@example.com', $entries[4]['detail']);
    }

    public function test_each_payment_page_visit_is_one_entry_with_its_steps(): void
    {
        $visit = (string) Str::uuid();
        $this->log('CHECKOUT_PAGE_OPENED', 'payment_session', $this->session->id, ['visit_id' => $visit], 'payer');
        $this->log('CHECKOUT_LOADED', 'payment_session', $this->session->id, ['visit_id' => $visit], 'payer');
        $this->log('PAYMENT_SUBMITTED', 'payment_session', $this->session->id, ['visit_id' => $visit], 'payer');
        $this->log('PAYMENT_ATTEMPT_FAILED', 'payment_session', $this->session->id, ['visit_id' => $visit, 'stage' => 'processing', 'message' => 'Insufficient funds'], 'payer');
        $this->log('PAYMENT_ATTEMPT_SUCCEEDED', 'payment_session', $this->session->id, ['visit_id' => $visit, 'amount_cents' => 70063], 'payer');

        $entries = $this->history()->json('entries');

        $this->assertCount(1, $entries);
        $this->assertSame('Customer opened the payment page', $entries[0]['title']);
        $this->assertSame('Submitted a payment · Payment failed: Insufficient funds · Paid $700.63', $entries[0]['detail']);
        $this->assertSame('success', $entries[0]['tone']);
    }

    public function test_back_to_back_plain_visits_fold_and_staff_visits_stay_separate(): void
    {
        foreach (['payer', 'payer', 'payer', 'staff'] as $actor) {
            $this->log('CHECKOUT_PAGE_OPENED', 'payment_session', $this->session->id, ['visit_id' => (string) Str::uuid()], $actor);
        }

        $entries = $this->history()->json('entries');

        $this->assertSame(['Staff opened the payment page', 'Customer opened the payment page 3 times'], array_column($entries, 'title'));
        $this->assertNotNull($entries[1]['last_at']);
    }

    public function test_payments_show_the_amount(): void
    {
        $txn = Transaction::query()->forceCreate([
            'id'                 => (string) Str::uuid(),
            'payment_session_id' => $this->session->id,
            'invoice_id'         => $this->invoice->id,
            'routing_rule_id'    => DB::table('routing_rules')->value('id'),
            'gateway'            => 'fluidpay',
            'mid'                => 'mid-1',
            'gateway_token'      => 'tok',
            'status'             => 'APPROVED',
            'fund_type'          => 'OPERATING',
            'amount_cents'       => 70063,
            'currency'           => 'USD',
        ]);

        $this->log('PAYMENT_APPROVED', 'transaction', $txn->id);
        $this->log('PMS_PAYMENT_RECORD_FAILED', 'invoice', $this->invoice->id, ['transaction_id' => $txn->id, 'error' => 'QBO 500: internal']);

        $entries = $this->history()->json('entries');

        $this->assertSame('Payment could not be recorded in QuickBooks', $entries[0]['title']);
        $this->assertStringNotContainsString('QBO 500', (string) $entries[0]['detail'], 'Raw system errors are not shown.');
        $this->assertSame(['Payment approved', '$700.63'], [$entries[1]['title'], $entries[1]['detail']]);
    }

    public function test_another_clients_invoice_is_not_found(): void
    {
        $this->getJson(route('inbound.clients.invoice-history', [
            'pms_client_id' => 'e944ba76-0000-4000-8000-000000000099',
            'invoice'       => $this->invoice->id,
        ]))->assertNotFound();
    }

    private function history()
    {
        return $this->getJson(route('inbound.clients.invoice-history', [
            'pms_client_id' => self::CLIENT,
            'invoice'       => $this->invoice->id,
        ]));
    }

    private function log(string $event, string $entityType, string $entityId, array $payload = [], string $actor = 'system'): void
    {
        AuditLog::query()->create([
            'event_type'  => $event,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'actor_type'  => $actor,
            'payload'     => $payload,
            'trace_id'    => 'test',
            'created_at'  => now()->addMinutes($this->minute++),
        ]);
    }
}
