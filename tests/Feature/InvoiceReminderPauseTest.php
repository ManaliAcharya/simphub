<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Models\PaymentSessionReminder;
use Modules\Billing\Services\PaymentSessionService;
use Modules\Inbound\Http\Controllers\ClientConfigController;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Services\PmsConnectorRegistry;
use Modules\Payment\Jobs\SendInvoiceReminder;
use Modules\Payment\Services\InvoiceLiveStatusResolver;
use Modules\Payment\Services\InvoiceReminderPause;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Pause / Resume reminders per invoice (every PMS). Pause stops them — e.g. a check is in
 * the mail. Resume sends one now and restarts the reminder schedule from that moment.
 */
class InvoiceReminderPauseTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->mock(InvoiceLiveStatusResolver::class, fn ($mock) => $mock->shouldReceive('resolve')->andReturn('unpaid'));

        $this->client = Client::query()->forceCreate([
            'id'                     => (string) Str::uuid(),
            'pms_client_id'          => (string) Str::uuid(),
            'client_name'            => 'Acme Accounting',
            'client_pms'             => 'QUICKBOOKS',
            'reminders_enabled'      => true,
            'reminder_schedule_days' => [3, 7, 14],
        ]);
    }

    public function test_pause_stops_reminders_until_resumed(): void
    {
        $session = $this->sentSession(now()->subDays(4));
        $session->forceFill(['next_reminder_at' => now()->subDay()])->save();

        app(InvoiceReminderPause::class)->pause($session);

        $this->assertNotNull($session->fresh()->reminders_paused_at);
        $this->assertNull($session->fresh()->next_reminder_at);

        // A reminder queued before the pause doesn't go out either.
        Queue::fake();
        Artisan::call('reminders:dispatch');
        Queue::assertNothingPushed();

        $this->runJob($session);
        $this->assertSame(0, $session->fresh()->reminders_sent_count);
        $this->assertSame(0, PaymentSessionReminder::query()->count());
    }

    public function test_resume_sends_a_reminder_now_and_restarts_the_schedule(): void
    {
        $this->travelTo(now()->startOfMinute());

        // First email 10 days ago; reminders 1 (day 3) and 2 (day 7) already went out.
        $session = $this->sentSession(now()->subDays(10));
        $session->forceFill(['reminders_sent_count' => 2, 'last_reminder_sent_at' => now()->subDays(3)])->save();
        PaymentSessionReminder::query()->create(['payment_session_id' => $session->id, 'reminder_number' => 1, 'day_offset' => 3, 'status' => 'sent']);
        PaymentSessionReminder::query()->create(['payment_session_id' => $session->id, 'reminder_number' => 2, 'day_offset' => 7, 'status' => 'sent']);

        $pauses = app(InvoiceReminderPause::class);
        $pauses->pause($session);

        Queue::fake();
        $pauses->resume($session->fresh(), $this->client);
        Queue::assertPushed(SendInvoiceReminder::class, fn ($job) => $job->paymentSessionId === $session->id && $job->resumed);

        // The restarted schedule's first step is set before the immediate reminder goes out.
        $resumed = $session->fresh();
        $this->assertNull($resumed->reminders_paused_at);
        $this->assertTrue($resumed->next_reminder_at->equalTo(now()->addDays(3)));

        // The immediate reminder: sent now, outside the schedule.
        $this->runJob($session, resumed: true);

        $afterResume = $session->fresh();
        $this->assertSame(3, $afterResume->reminders_sent_count);
        $this->assertSame(0, $afterResume->reminderStepIndex());
        $this->assertTrue($afterResume->next_reminder_at->equalTo(now()->addDays(3)));
        $this->assertDatabaseHas('payment_session_reminders', ['payment_session_id' => $session->id, 'reminder_number' => 3, 'day_offset' => 0, 'status' => 'sent', 'reason' => 'resumed']);

        // 3 days later the schedule continues from the resume: step 1 (day 3), then day 7.
        $this->travel(3)->days();
        $this->runJob($session);

        $afterStep = $session->fresh();
        $this->assertSame(4, $afterStep->reminders_sent_count);
        $this->assertDatabaseHas('payment_session_reminders', ['payment_session_id' => $session->id, 'reminder_number' => 4, 'day_offset' => 3, 'status' => 'sent']);
        $this->assertTrue($afterStep->next_reminder_at->equalTo($afterStep->reminder_cycle_started_at->copy()->addDays(7)));
    }

    public function test_resume_never_reminds_about_a_paid_invoice(): void
    {
        $this->mock(InvoiceLiveStatusResolver::class, fn ($mock) => $mock->shouldReceive('resolve')->andReturn('paid'));

        $session = $this->sentSession(now()->subDays(5));
        $this->runJob($session, resumed: true);

        $this->assertSame(0, $session->fresh()->reminders_sent_count);
        $this->assertSame('paid', $session->fresh()->link_status);
    }

    public function test_pause_is_only_offered_while_reminders_can_still_go_out(): void
    {
        $session = $this->sentSession(now());
        $invoice = $session->invoice;

        $this->assertTrue(InvoiceReminderPause::available($this->client, $invoice, $session));
        $this->assertFalse(InvoiceReminderPause::available($this->client, $invoice, null));
        $this->assertFalse(InvoiceReminderPause::available($this->client, (clone $invoice)->forceFill(['status' => 'PAID']), $session));
        $this->assertFalse(InvoiceReminderPause::available($this->client, $invoice, (clone $session)->forceFill(['first_email_sent_at' => null])));
        $this->assertFalse(InvoiceReminderPause::available($this->client->forceFill(['reminders_enabled' => false]), $invoice, $session));
    }

    public function test_an_invoice_of_another_client_cannot_be_paused(): void
    {
        $session = $this->sentSession(now());
        $other   = Client::query()->forceCreate([
            'id' => (string) Str::uuid(), 'pms_client_id' => (string) Str::uuid(), 'client_name' => 'Other', 'client_pms' => 'QUICKBOOKS',
        ]);

        try {
            app(ClientConfigController::class)->updateInvoiceReminders(
                Request::create('/', 'POST', ['action' => 'pause']),
                $other->pms_client_id,
                $session->invoice,
                app(InvoiceReminderPause::class),
            );
            $this->fail('Expected a 404 for an invoice of another client.');
        } catch (NotFoundHttpException) {
            $this->assertNull($session->fresh()->reminders_paused_at);
        }
    }

    /** Same ownership check on the Resend button next to Pause. */
    public function test_an_invoice_of_another_client_cannot_be_resent(): void
    {
        $session = $this->sentSession(now());
        $other   = Client::query()->forceCreate([
            'id' => (string) Str::uuid(), 'pms_client_id' => (string) Str::uuid(), 'client_name' => 'Other', 'client_pms' => 'QUICKBOOKS',
        ]);

        try {
            app(ClientConfigController::class)->resendInvoice($other->pms_client_id, $session->invoice, app(PmsConnectorRegistry::class));
            $this->fail('Expected a 404 for an invoice of another client.');
        } catch (NotFoundHttpException) {
            Mail::assertNothingSent();
        }
    }

    private function sentSession(\DateTimeInterface $firstEmailAt): PaymentSession
    {
        $invoice = Invoice::query()->forceCreate([
            'pms_source'          => 'quickbooks',
            'pms_client_id'       => $this->client->pms_client_id,
            'external_invoice_id' => (string) Str::uuid(),
            'external_client_id'  => 'customer-1',
            'invoice_number'      => '1001',
            'status'              => 'SENT',
            'fund_type'           => 'OPERATING',
            'amount_cents'        => 25000,
            'currency'            => 'USD',
            'recipient_emails'    => ['client@example.com'],
        ]);

        $session = app(PaymentSessionService::class)->create($invoice);
        $session->forceFill([
            'payment_link_sent_at' => $firstEmailAt,
            'first_email_sent_at'  => $firstEmailAt,
        ])->save();

        return $session->fresh(['invoice']);
    }

    private function runJob(PaymentSession $session, bool $resumed = false): void
    {
        app()->call([new SendInvoiceReminder($session->id, $resumed), 'handle']);
    }
}
