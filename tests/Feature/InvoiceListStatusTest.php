<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Payment\Services\InvoiceListStatus;
use Tests\TestCase;

class InvoiceListStatusTest extends TestCase
{
    private InvoiceListStatus $statuses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statuses = new InvoiceListStatus;
    }

    private function invoice(array $attributes = []): Invoice
    {
        return (new Invoice)->forceFill(array_merge([
            'status'           => 'PENDING',
            'recipient_emails' => ['payer@example.com'],
        ], $attributes));
    }

    private function paymentSession(array $attributes = []): PaymentSession
    {
        return (new PaymentSession)->forceFill(array_merge([
            'status'      => 'AWAITING_PAYMENT',
            'link_status' => 'active',
        ], $attributes));
    }

    private function sent(array $attributes = []): PaymentSession
    {
        return $this->paymentSession(array_merge([
            'payment_link_sent_at'      => Carbon::parse('2026-10-20 09:00'),
            'payment_link_last_sent_to' => ['payer@example.com'],
        ], $attributes));
    }

    // ── Email status ────────────────────────────────────────────────────

    public function test_email_not_sent(): void
    {
        $this->assertSame('Not sent', $this->statuses->emailStatus($this->invoice(), $this->paymentSession(), null)['label']);
        $this->assertSame('Not sent', $this->statuses->emailStatus($this->invoice(), null, null)['label']);
    }

    public function test_email_not_sent_says_why(): void
    {
        $this->assertSame(
            'No recipient email on the invoice',
            $this->statuses->emailStatus($this->invoice(['recipient_emails' => []]), $this->paymentSession(), null)['detail'],
        );
        $this->assertStringStartsWith(
            'Held',
            $this->statuses->emailStatus($this->invoice(), $this->paymentSession(), null, held: true)['detail'],
        );
    }

    public function test_email_sent_and_resent(): void
    {
        $sent = $this->statuses->emailStatus($this->invoice(), $this->sent(), null);
        $this->assertSame('Sent', $sent['label']);
        $this->assertTrue($sent['at']->eq(Carbon::parse('2026-10-20 09:00')));

        $resent = $this->statuses->emailStatus($this->invoice(), $this->sent(['last_email_sent_at' => Carbon::parse('2026-10-22 10:00')]), null);
        $this->assertSame('Resent', $resent['label']);
        $this->assertTrue($resent['at']->eq(Carbon::parse('2026-10-22 10:00')));
    }

    public function test_a_first_send_that_reached_nobody_is_send_failed(): void
    {
        $status = $this->statuses->emailStatus($this->invoice(), $this->sent(['payment_link_last_sent_to' => []]), null);

        $this->assertSame('Send failed', $status['label']);
    }

    public function test_a_failed_resend_after_a_good_send_is_send_failed(): void
    {
        $status = $this->statuses->emailStatus(
            $this->invoice(),
            $this->sent(),
            ['at' => Carbon::parse('2026-10-23 08:00'), 'reason' => 'Mailbox unavailable'],
        );

        $this->assertSame('Send failed', $status['label']);
        $this->assertSame('Mailbox unavailable', $status['detail']);
    }

    public function test_a_good_resend_after_a_failure_is_resent(): void
    {
        $status = $this->statuses->emailStatus(
            $this->invoice(),
            $this->sent(['last_email_sent_at' => Carbon::parse('2026-10-24 08:00')]),
            ['at' => Carbon::parse('2026-10-23 08:00'), 'reason' => 'Mailbox unavailable'],
        );

        $this->assertSame('Resent', $status['label']);
    }

    // ── Payment status ──────────────────────────────────────────────────

    public function test_payment_pending(): void
    {
        $this->assertSame('Pending', $this->statuses->paymentStatus($this->invoice(['status' => 'SENT']), $this->sent(), [], null)['label']);
    }

    public function test_a_pms_sync_does_not_hide_a_failed_attempt(): void
    {
        // Checkout set FAILED, then a QuickBooks sync overwrote invoices.status with SENT.
        $status = $this->statuses->paymentStatus(
            $this->invoice(['status' => 'SENT']),
            $this->sent(['status' => 'FAILED']),
            [],
            ['at' => Carbon::parse('2026-10-22 11:00'), 'reason' => 'Card declined'],
        );

        $this->assertSame('Failed', $status['label']);
        $this->assertSame('Card declined', $status['detail']);
    }

    public function test_payment_success_from_our_transaction_or_the_pms(): void
    {
        $this->assertSame('Success', $this->statuses->paymentStatus($this->invoice(['status' => 'SENT']), $this->sent(['status' => 'COMPLETED']), ['CAPTURED'], null)['label']);

        $paidInPms = $this->statuses->paymentStatus($this->invoice(['status' => 'PAID']), $this->sent(), [], null);
        $this->assertSame('Success', $paidInPms['label']);
        $this->assertSame('Marked paid in the PMS', $paidInPms['detail']);
    }

    public function test_payment_processing_refunded_voided_and_returned(): void
    {
        $this->assertSame('Processing', $this->statuses->paymentStatus($this->invoice(), $this->sent(['status' => 'PROCESSING']), [], null)['label']);
        $this->assertSame('Refunded', $this->statuses->paymentStatus($this->invoice(), $this->sent(), ['CAPTURED', 'REFUNDED'], null)['label']);
        $this->assertSame('Voided', $this->statuses->paymentStatus($this->invoice(), $this->sent(['link_status' => 'voided']), [], null)['label']);
        $this->assertSame('Voided', $this->statuses->paymentStatus($this->invoice(['status' => 'CANCELLED']), $this->sent(), ['VOIDED'], null)['label']);
        $this->assertSame('Returned', $this->statuses->paymentStatus($this->invoice(), $this->sent(), ['RETURNED'], null)['label']);
        $this->assertSame('Link disabled', $this->statuses->paymentStatus($this->invoice(), $this->sent(['link_status' => 'disabled']), [], null)['label']);
    }

    // ── The listing, end to end ─────────────────────────────────────────

    public function test_resend_after_a_failed_payment_keeps_both_columns_right(): void
    {
        // The real migrations are MySQL-only; just the columns this reads.
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
        Schema::create('payment_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
            $table->string('status');
            $table->string('link_status')->default('active');
            $table->timestamp('payment_link_sent_at')->nullable();
            $table->json('payment_link_last_sent_to')->nullable();
            $table->timestamp('last_email_sent_at')->nullable();
            $table->integer('reminders_sent_count')->default(0);
            $table->timestamp('last_reminder_sent_at')->nullable();
            $table->timestamps();
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
            $table->string('status');
        });

        $invoice = $this->invoice(['id' => (string) str()->uuid(), 'status' => 'SENT']);

        $session = PaymentSession::query()->forceCreate([
            'invoice_id'                => $invoice->id,
            'status'                    => 'FAILED',
            'payment_link_sent_at'      => now()->subDays(3),
            'payment_link_last_sent_to' => ['payer@example.com'],
            'last_email_sent_at'        => now(),
        ]);

        AuditLogger::log('PAYMENT_ATTEMPT_FAILED', 'payment_session', $session->id, [
            'message' => 'Online payment is not available for this invoice right now. Please contact the firm.',
        ], 'payer');

        $row = $this->statuses->forInvoices(collect([$invoice]))[$invoice->id];

        $this->assertSame('Resent', $row['email']['label']);
        $this->assertSame('Failed', $row['payment']['label']);
        $this->assertStringContainsString('contact the firm', $row['payment']['detail']);
    }
}
