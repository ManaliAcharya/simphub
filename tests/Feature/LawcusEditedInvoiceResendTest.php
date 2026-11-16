<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Inbound\Jobs\PollLawcusInvoicesJob;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\LawcusConnection;
use Modules\Inbound\Services\LawcusInvoiceIngestionService;
use Modules\Payment\Mail\PaymentLinkMail;
use Tests\TestCase;

/**
 * Firms add late fees and record partial payments by editing a saved Lawcus invoice and
 * re-saving it. A changed amount due resends the link, which then charges the new amount.
 */
class LawcusEditedInvoiceResendTest extends TestCase
{
    use RefreshDatabase;

    private string $amountDue = '100.00';

    public function test_a_changed_amount_due_is_picked_up_again(): void
    {
        $stored = new Invoice(['amount_cents' => 10000, 'status' => 'SENT']);

        $this->assertTrue(PollLawcusInvoicesJob::amountDueChanged(['amount_due' => '110.00'], $stored));
        $this->assertTrue(PollLawcusInvoicesJob::amountDueChanged(['amount_due' => '40.00'], $stored));
        $this->assertFalse(PollLawcusInvoicesJob::amountDueChanged(['amount_due' => '100.00'], $stored));
        $this->assertFalse(PollLawcusInvoicesJob::amountDueChanged(['amount_due' => '100'], $stored));
    }

    public function test_paid_or_settled_invoices_are_not_picked_up_again(): void
    {
        $this->assertFalse(PollLawcusInvoicesJob::amountDueChanged(['amount_due' => '110.00'], new Invoice(['amount_cents' => 10000, 'status' => 'PAID'])));
        $this->assertFalse(PollLawcusInvoicesJob::amountDueChanged(['amount_due' => '0.00'], new Invoice(['amount_cents' => 10000, 'status' => 'SENT'])));
        $this->assertFalse(PollLawcusInvoicesJob::amountDueChanged([], new Invoice(['amount_cents' => 10000, 'status' => 'SENT'])));
    }

    public function test_late_fee_and_partial_payment_edits_resend_the_link_with_the_new_amount(): void
    {
        $client = $this->lawcusClient();

        $first = $this->ingest($client);
        $this->assertSame(1, $first['emails_sent']);
        $this->assertSame(10000, $first['invoice']->amount_cents);

        // Late fee: +10%.
        $this->amountDue = '110.00';
        $lateFee = $this->ingest($client);

        $this->assertSame(1, $lateFee['emails_sent']);
        $this->assertSame(11000, $lateFee['invoice']->amount_cents);
        $this->assertSame($first['payment_session']->id, $lateFee['payment_session']->id, 'the same link charges the new amount');
        Mail::assertSent(PaymentLinkMail::class, fn (PaymentLinkMail $mail) => $mail->isResend
            && $mail->invoice->amount_cents === 11000
            && str_starts_with($mail->envelope()->subject, 'Updated: ')
            && str_contains($mail->envelope()->subject, '#00042'));

        // Re-saved without an amount change (e.g. a note or line edited): resent too.
        $this->assertSame(1, $this->ingest($client)['emails_sent']);

        // Partial payment recorded in Lawcus lowers what's due.
        $this->amountDue = '60.00';
        $partial = $this->ingest($client);

        $this->assertSame(1, $partial['emails_sent']);
        $this->assertSame(6000, $partial['invoice']->amount_cents);
        Mail::assertSent(PaymentLinkMail::class, 4);
    }

    public function test_any_save_after_the_last_read_is_picked_up_again_but_a_send_from_lawcus_is_not(): void
    {
        $stored = new Invoice(['amount_cents' => 10000, 'status' => 'SENT']);
        $stored->raw_payload = ['invoice' => ['data' => ['updated_at' => '2026-11-16 10:00:00']]];

        $row = fn (array $overrides) => $overrides + ['amount_due' => '100.00', 'sent_at' => '2026-11-16 09:59:00'];

        $this->assertTrue(PollLawcusInvoicesJob::savedAgain($row(['updated_at' => '2026-11-16 10:05:00']), $stored));
        $this->assertFalse(PollLawcusInvoicesJob::savedAgain($row(['updated_at' => '2026-11-16 10:00:00']), $stored), 'not changed');
        $this->assertFalse(PollLawcusInvoicesJob::savedAgain($row(['updated_at' => '2026-11-16T10:00:00.400Z']), $stored), 'same second');
        $this->assertFalse(PollLawcusInvoicesJob::savedAgain($row(['updated_at' => '2026-11-16 10:05:00', 'sent_at' => '2026-11-16 10:05:00']), $stored), 'sent from Lawcus');
        $this->assertFalse(PollLawcusInvoicesJob::savedAgain($row(['updated_at' => '2026-11-16 10:05:00', 'amount_due' => '0.00']), $stored), 'nothing due');
        $this->assertFalse(PollLawcusInvoicesJob::savedAgain($row([]), $stored), 'no updated_at on the row');

        $paid = new Invoice(['amount_cents' => 10000, 'status' => 'PAID']);
        $paid->raw_payload = $stored->raw_payload;
        $this->assertFalse(PollLawcusInvoicesJob::savedAgain($row(['updated_at' => '2026-11-16 10:05:00']), $paid), 'paid through us');

        $readBefore = new Invoice(['amount_cents' => 10000, 'status' => 'SENT']);
        $readBefore->raw_payload = ['invoice' => ['data' => []]];
        $this->assertFalse(PollLawcusInvoicesJob::savedAgain($row(['updated_at' => '2026-11-16 10:05:00']), $readBefore), 'no stored updated_at');
    }

    public function test_a_resave_resends_a_paused_invoice_and_restarts_its_reminders(): void
    {
        $client = $this->lawcusClient();
        $client->forceFill(['reminders_enabled' => true])->save();

        $session = $this->ingest($client)['payment_session'];
        $this->travel(5)->days();
        $session->forceFill([
            'reminders_paused_at'  => now(),
            'next_reminder_at'     => null,
            'reminders_sent_count' => 1,
        ])->save();

        // Check in the mail, so paused — then the firm adds a 10% late fee.
        $this->travel(10)->days();
        $this->amountDue = '110.00';
        $edited = $this->ingest($client);

        $this->assertSame(1, $edited['emails_sent']);

        $session->refresh();
        $this->assertNull($session->reminders_paused_at);
        $this->assertSame(now()->toDateTimeString(), $session->reminder_cycle_started_at->toDateTimeString());
        $this->assertSame(1, (int) $session->reminder_cycle_offset);
        $this->assertSame(
            now()->addDays((int) $client->fresh()->reminderScheduleDays()[0])->toDateTimeString(),
            $session->next_reminder_at->toDateTimeString(),
        );
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'payment_reminders_restarted_on_resend']);
    }

    public function test_no_resend_when_auto_resend_is_off_but_the_amount_still_updates(): void
    {
        $client = $this->lawcusClient(autoResend: false);
        $this->ingest($client);

        $this->amountDue = '110.00';
        $edited = $this->ingest($client);

        $this->assertSame(0, $edited['emails_sent']);
        $this->assertSame(11000, $edited['invoice']->amount_cents);
        Mail::assertSent(PaymentLinkMail::class, 1);
    }

    private function ingest(Client $client): array
    {
        return app(LawcusInvoiceIngestionService::class)->ingest('inv-uuid-42', [
            'pms_client_id' => $client->pms_client_id,
            'event_name'    => 'poll.invoice.updated',
        ]);
    }

    private function lawcusClient(bool $autoResend = true): Client
    {
        Mail::fake();
        config()->set('services.lawcus.rest_api_base_url', 'https://api.us.lawcus.com');

        $client = Client::query()->forceCreate([
            'id'                    => (string) Str::uuid(),
            'pms_client_id'         => (string) Str::uuid(),
            'client_name'           => 'RR Law',
            'client_pms'            => 'LAWCUS',
            'auto_resend_on_change' => $autoResend,
        ]);

        LawcusConnection::query()->create([
            'provider'      => 'lawcus',
            'pms_client_id' => $client->pms_client_id,
            'access_token'  => 'api-token',
        ]);

        Http::fake([
            'https://api.us.lawcus.com/invoices/*' => fn () => Http::response([
                'invoice' => [
                    'id'           => 'inv-uuid-42',
                    'number'       => '00042',
                    'status'       => 'SENT',
                    'type'         => 'DEFAULT',
                    'total'        => '100.00',
                    'amount_due'   => $this->amountDue,
                    'currency'     => 'USD',
                    'client_email' => 'client@example.com',
                    'client'       => ['id' => 9, 'email' => 'client@example.com'],
                    'timeentries'  => [],
                    'flatfees'     => [['id' => 'f1', 'quantity' => 1, 'rate' => '100.00']],
                    'expenses'     => [],
                ],
                'payments' => [],
            ]),
        ]);

        return $client;
    }
}
