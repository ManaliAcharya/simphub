<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\ClioConnection;
use Tests\TestCase;

class ClioInvoiceIngestionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_clio_invoice_ingestion_api_fetches_invoice_and_creates_session(): void
    {
        ClioConnection::query()->create([
            'provider' => 'clio',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);

        config()->set('services.clio.api_base_url', 'https://app.clio.com');

        Http::fake([
            'https://app.clio.com/api/v4/bills/123.json' => Http::response([
                'data' => [
                    'id' => 123,
                    'state' => 'open',
                    'total' => '500.00',
                    'currency' => 'usd',
                    'client' => [
                        'id' => 'client-9',
                    ],
                    'matter' => [
                        'id' => 'matter-7',
                    ],
                ],
            ]),
        ]);

        $response = $this->postJson('/api/v1/inbound/invoices/clio', [
            'invoice_id' => '123',
            'fund_type' => 'TRUST',
        ]);

        $response->assertAccepted();
        $response->assertJson([
            'status' => 'ok',
            'external_invoice_id' => '123',
        ]);

        $this->assertDatabaseHas('invoices', [
            'pms_source' => 'clio',
            'external_invoice_id' => '123',
            'external_client_id' => 'client-9',
            'external_matter_id' => 'matter-7',
            'fund_type' => 'TRUST',
            'amount_cents' => 50000,
            'pms_sync_status' => 'SYNCED',
        ]);

        $this->assertDatabaseCount('payment_sessions', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'INVOICE_RECEIVED',
            'entity_type' => 'invoice',
            'actor_type' => 'system',
        ]);
    }
}
