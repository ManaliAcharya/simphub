<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClioConnection;
use Modules\Inbound\Services\ClioInvoiceIngestionService;
use Modules\Payment\Services\PaymentLinkService;
use Tests\TestCase;

/**
 * Approving a bill in Clio sends the payment link; marking the bill sent in Clio right
 * after (MarkClioBillSentJob) makes Clio send another "updated" webhook. That echo must
 * not resend the link — only a change the client would see does.
 */
class ClioNoDuplicatePaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://app.clio.com';

    private array $bill = [];

    private int $firstSends = 0;

    private int $resends = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.clio.api_base_url' => self::API, 'services.clio.approved_bill_states' => ['AWAITING_PAYMENT']]);
        Mail::fake();
        Queue::fake();

        $this->bill = [
            'id' => 123, 'number' => '92', 'kind' => 'revenue_kind', 'state' => 'awaiting_payment',
            'total' => '800.00', 'balance' => '800.00', 'currency' => 'usd', 'due_at' => '2026-12-04',
            'client' => ['id' => 'client-9', 'name' => 'Pat Doe', 'primary_email_address' => 'pat@example.com'],
            'matters' => [['id' => 77]],
        ];

        Http::fake([
            self::API.'/api/v4/bills/123.json*' => fn () => Http::response(['data' => $this->bill]),
            self::API.'/api/v4/matters/*'       => Http::response(['data' => ['id' => 77, 'custom_field_values' => []]]),
            self::API.'/api/v4/contacts/*'      => Http::response(['data' => ['id' => 'client-9', 'custom_field_values' => []]]),
        ]);

        $links = Mockery::mock(PaymentLinkService::class);
        $links->shouldReceive('sendInvoiceLinkOnce')->andReturnUsing(function ($invoice, PaymentSession $session) {
            // What the real once-only claim does.
            if ($session->fresh()->payment_link_sent_at !== null) {
                return 0;
            }
            $session->forceFill(['payment_link_sent_at' => now()])->save();
            $this->firstSends++;

            return 1;
        });
        $links->shouldReceive('resendPaymentLink')->andReturnUsing(function () {
            $this->resends++;

            return 1;
        });
        $this->app->instance(PaymentLinkService::class, $links);
    }

    public function test_the_update_clio_sends_after_mark_as_sent_does_not_resend_the_link(): void
    {
        $client = $this->firm();

        $this->ingest($client);   // approval: first send
        $this->ingest($client);   // Clio's echo of our mark_as_sent
        $this->ingest($client);   // any further no-op touch

        $this->assertSame(1, $this->firstSends);
        $this->assertSame(0, $this->resends, 'an unchanged bill must not be emailed again');
    }

    public function test_a_changed_amount_or_due_date_still_resends(): void
    {
        $client = $this->firm();

        $this->ingest($client);
        $this->ingest($client);

        $this->bill['total'] = $this->bill['balance'] = '880.00';
        $this->ingest($client);
        $this->assertSame(1, $this->resends);

        $this->bill['due_at'] = '2026-12-18';
        $this->ingest($client);
        $this->assertSame(2, $this->resends);

        $this->ingest($client);
        $this->assertSame(2, $this->resends);
    }

    public function test_a_changed_client_email_resends(): void
    {
        $client = $this->firm();

        $this->ingest($client);

        $this->bill['client']['primary_email_address'] = 'pat.new@example.com';
        $this->ingest($client);

        $this->assertSame(1, $this->resends);
    }

    private function ingest(Client $client): array
    {
        return app(ClioInvoiceIngestionService::class)->ingest('123', ['pms_client_id' => $client->pms_client_id]);
    }

    private function firm(): Client
    {
        $client = Client::query()->forceCreate([
            'id'                    => (string) Str::uuid(),
            'pms_client_id'         => (string) Str::uuid(),
            'client_name'           => 'Korkin Law',
            'client_pms'            => 'CLIO',
            'auto_resend_on_change' => true,
        ]);

        ClioConnection::query()->create([
            'provider'         => 'clio',
            'pms_client_id'    => $client->pms_client_id,
            'access_token'     => 'access-token',
            'refresh_token'    => 'refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);

        return $client;
    }
}
