<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\ClioConnection;
use Tests\TestCase;

class ClioIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_connect_route_redirects_to_clio_authorize_url(): void
    {
        config()->set('services.clio.client_id', 'client-id');
        config()->set('services.clio.redirect_uri', 'https://example.test/inbound/clio/callback');

        $response = $this->get('/inbound/clio/connect');

        $response->assertRedirect();
        $this->assertStringContainsString('https://app.clio.com/oauth/authorize', $response->headers->get('Location'));
        $this->assertStringContainsString('client_id=client-id', $response->headers->get('Location'));
    }

    public function test_integration_page_loads(): void
    {
        $response = $this->get('/inbound/clio');

        $response->assertOk();
        $response->assertSee('Connect Clio');
    }

    public function test_callback_exchanges_token_and_registers_webhook(): void
    {
        config()->set('services.clio.client_id', 'client-id');
        config()->set('services.clio.client_secret', 'client-secret');
        config()->set('services.clio.redirect_uri', 'https://example.test/inbound/clio/callback');
        config()->set('services.clio.webhook_callback_url', 'https://example.test/api/v1/inbound/webhooks/clio');

        Http::fake([
            'https://app.clio.com/oauth/token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ]),
            'https://app.clio.com/api/v4/webhooks' => Http::response([
                'data' => [
                    'id' => 99,
                    'url' => 'https://example.test/api/v1/inbound/webhooks/clio',
                    'expires_at' => now()->addDays(30)->toIso8601String(),
                ],
            ]),
        ]);

        $location = $this->get('/inbound/clio/connect')->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY) ?: '', $query);

        $response = $this->get('/inbound/clio/callback?code=test-code&state='.urlencode($query['state']));

        $response->assertRedirect('/inbound/clio?success=Clio%20connected%20and%20webhook%20registered.&webhook_id=99');

        $this->assertDatabaseHas('clio_connections', [
            'provider' => 'clio',
            'webhook_id' => '99',
        ]);
    }

    public function test_clio_webhook_secret_handshake_is_echoed_and_stored(): void
    {
        ClioConnection::query()->create(['provider' => 'clio']);

        $response = $this->postJson('/api/v1/inbound/webhooks/clio', [], [
            'X-Hook-Secret' => 'shared-secret',
        ]);

        $response->assertOk();
        $response->assertHeader('X-Hook-Secret', 'shared-secret');
        $this->assertSame('shared-secret', ClioConnection::query()->firstOrFail()->webhook_secret);
    }
}
