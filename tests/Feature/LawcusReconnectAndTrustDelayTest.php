<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Inbound\Jobs\PollLawcusInvoicesJob;
use Modules\Inbound\Jobs\SendTrustRequestLinkJob;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\LawcusConnection;
use Modules\Inbound\Services\LawcusOAuthService;
use Tests\TestCase;

/**
 * Reconnecting a firm with a different Lawcus token starts picking up invoices from that
 * moment, so nothing created before (e.g. that day's billing run) is ever emailed. A new
 * Trust Request's link waits two minutes, so a check the firm already has can be recorded.
 */
class LawcusReconnectAndTrustDelayTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_token_restarts_pickup_from_the_moment_it_was_pasted(): void
    {
        $client = $this->client();
        $oauth  = app(LawcusOAuthService::class);

        $this->travelTo('2026-11-02 08:00:00');
        $oauth->persistPastedToken('test-token', $client->pms_client_id);
        $this->assertSame('2026-11-02 08:00:00', PollLawcusInvoicesJob::pollingBaseline($this->connection($client))->toDateTimeString());

        // Live token pasted mid-afternoon, after the morning's billing run.
        $this->travelTo('2026-11-16 15:30:00');
        $oauth->persistPastedToken('live-token', $client->pms_client_id);

        $connection = $this->connection($client);
        $this->assertSame(1, LawcusConnection::query()->where('pms_client_id', $client->pms_client_id)->count());
        $this->assertSame('2026-11-16 15:30:00', PollLawcusInvoicesJob::pollingBaseline($connection)->toDateTimeString());

        $row = ['id' => 'x', 'status' => 'APPROVED', 'amount_due' => '50.00', 'issue_date' => '2026-11-16 00:00:00'];
        $this->assertFalse(PollLawcusInvoicesJob::shouldIngest($row + ['created_at' => '2026-11-16 09:05:00'], PollLawcusInvoicesJob::pollingBaseline($connection)));
        $this->assertTrue(PollLawcusInvoicesJob::shouldIngest($row + ['created_at' => '2026-11-16 15:45:00'], PollLawcusInvoicesJob::pollingBaseline($connection)));
    }

    public function test_repasting_the_same_token_keeps_the_start(): void
    {
        $client = $this->client();
        $oauth  = app(LawcusOAuthService::class);

        $this->travelTo('2026-11-16 15:30:00');
        $oauth->persistPastedToken('live-token', $client->pms_client_id);

        $this->travelTo('2026-11-18 10:00:00');
        $oauth->persistPastedToken('live-token', $client->pms_client_id);

        $this->assertSame('2026-11-16 15:30:00', PollLawcusInvoicesJob::pollingBaseline($this->connection($client))->toDateTimeString());
    }

    public function test_connections_from_before_keep_the_start_of_their_day(): void
    {
        $client     = $this->client();
        $connection = LawcusConnection::query()->forceCreate([
            'provider'      => 'lawcus',
            'pms_client_id' => $client->pms_client_id,
            'access_token'  => 'old',
            'created_at'    => '2026-10-28 11:00:00',
        ]);

        $this->assertSame('2026-10-28 00:00:00', PollLawcusInvoicesJob::pollingBaseline($connection)->toDateTimeString());
    }

    public function test_a_trust_request_link_waits_two_minutes(): void
    {
        Queue::fake();
        $this->travelTo('2026-11-16 12:00:00');

        $invoice = (new Invoice)->forceFill(['id' => (string) Str::uuid()]);
        SendTrustRequestLinkJob::dispatchDelayed($invoice);

        Queue::assertPushed(SendTrustRequestLinkJob::class, fn ($job) => $job->delay->equalTo(now()->addMinutes(2)));
    }

    private function client(): Client
    {
        return Client::query()->forceCreate([
            'id'            => (string) Str::uuid(),
            'pms_client_id' => (string) Str::uuid(),
            'client_name'   => 'RR Law',
            'client_pms'    => 'LAWCUS',
        ]);
    }

    private function connection(Client $client): LawcusConnection
    {
        return LawcusConnection::query()->where('pms_client_id', $client->pms_client_id)->firstOrFail();
    }
}
