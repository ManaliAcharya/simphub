<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Http\Middleware\MerchantSessionMiddleware;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\PmsConnection;
use Tests\TestCase;

/**
 * Disconnecting Zoho Books removes the workflow rules and webhooks SimpHub created
 * (and nothing else in the organization), revokes the token and deletes the
 * connection. Zoho CRM has no webhooks, so it only revokes and deletes.
 */
class ZohoDisconnectTest extends TestCase
{
    private const BOOKS = '/books/v3';

    private const PMS_CLIENT_ID = '7a1c0c52-4a2e-4f7e-9d61-0f3b2a7c9e11';

    private const OTHER_CLIENT_ID = '0d6c7f0e-2b51-4d55-8a0b-3c3f1b8f6a22';

    /** @var array<string, mixed> "METHOD /path" => body, or a closure returning a response */
    private array $routes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(MerchantSessionMiddleware::class);

        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pms_client_id');
            $table->string('client_name')->nullable();
            $table->string('client_pms')->nullable();
            $table->string('zoho_region')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pms_connections', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->uuid('pms_client_id');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('meta')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        foreach ([self::PMS_CLIENT_ID, self::OTHER_CLIENT_ID] as $id) {
            Client::query()->forceCreate([
                'id' => (string) Str::uuid(),
                'pms_client_id' => $id,
                'client_name' => 'Acme',
                'client_pms' => 'ZOHO',
            ]);
        }

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/oauth/v2/token/revoke')) {
                return Http::response(['status' => 'success']);
            }

            $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen(self::BOOKS));
            $route = $this->routes[$request->method().' '.$path] ?? null;

            if ($route instanceof \Closure) {
                return $route($request);
            }

            return $route === null
                ? Http::response(['code' => 1002, 'message' => 'Not faked: '.$request->method().' '.$path], 404)
                : Http::response($route);
        });
    }

    public function test_books_disconnect_removes_only_our_workflows_and_webhooks_then_revokes(): void
    {
        $this->connection('zoho');
        $this->routes = [
            'GET /settings/workflows' => ['code' => 0, 'workflows' => [
                ['workflow_id' => 'wf-created', 'workflow_name' => 'Payment Middleware – Invoice Created'],
                ['workflow_id' => 'wf-updated', 'workflow_name' => 'Payment Middleware – Invoice Updated'],
                ['workflow_id' => 'wf-firm', 'workflow_name' => 'Firm reminder rule'],
            ]],
            'GET /settings/webhooks' => ['code' => 0, 'webhooks' => [
                ['webhook_id' => 'wh-create', 'webhook_name' => 'Payment Middleware – Invoice Notify'],
                ['webhook_id' => 'wh-update', 'webhook_name' => 'Payment Middleware – Invoice Update Notify'],
                ['webhook_id' => 'wh-firm', 'webhook_name' => 'Slack notify'],
            ]],
            'DELETE /settings/workflows/wf-created' => ['code' => 0],
            'DELETE /settings/workflows/wf-updated' => ['code' => 0],
            'DELETE /settings/webhooks/wh-create' => ['code' => 0],
            'DELETE /settings/webhooks/wh-update' => ['code' => 0],
        ];

        $this->post(route('inbound.zoho.disconnect'), ['pms_client_id' => self::PMS_CLIENT_ID])
            ->assertRedirect()
            ->assertRedirectContains('/inbound/zoho')
            ->assertRedirectContains(rawurlencode('2 workflow rule(s) and 2 webhook(s) were removed'));

        $deletes = $this->sent('DELETE');
        $this->assertSame([
            '/settings/workflows/wf-created',
            '/settings/workflows/wf-updated',
            '/settings/webhooks/wh-create',
            '/settings/webhooks/wh-update',
        ], $deletes, 'Rules go first (they use the webhooks), and the firm\'s own ones are left alone.');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'accounts.zoho.com/oauth/v2/token/revoke')
            && str_contains($r->url(), 'token=test-refresh-token'));
        $this->assertSame(0, PmsConnection::query()->count());
    }

    public function test_a_failed_cleanup_still_disconnects_and_says_what_to_delete_by_hand(): void
    {
        $this->connection('zoho');
        $this->routes = [
            'GET /settings/workflows' => fn () => Http::response(['code' => 57, 'message' => 'You are not authorized to perform this operation'], 401),
        ];

        $this->post(route('inbound.zoho.disconnect'), ['pms_client_id' => self::PMS_CLIENT_ID])
            ->assertRedirectContains('error=')
            ->assertRedirectContains(rawurlencode('Settings > Automation'));

        $this->assertSame([], $this->sent('DELETE'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/oauth/v2/token/revoke'));
        $this->assertSame(0, PmsConnection::query()->count());
    }

    public function test_a_webhook_already_deleted_in_zoho_does_not_stop_the_rest(): void
    {
        $this->connection('zoho');
        $this->routes = [
            'GET /settings/workflows' => ['code' => 0, 'workflows' => [
                ['workflow_id' => 'wf-created', 'workflow_name' => 'Payment Middleware – Invoice Created'],
            ]],
            'GET /settings/webhooks' => ['code' => 0, 'webhooks' => [
                ['webhook_id' => 'wh-gone', 'webhook_name' => 'Payment Middleware – Invoice Notify'],
                ['webhook_id' => 'wh-update', 'webhook_name' => 'Payment Middleware – Invoice Update Notify'],
            ]],
            'DELETE /settings/workflows/wf-created' => ['code' => 0],
            // wh-gone is not faked, so it answers 404.
            'DELETE /settings/webhooks/wh-update' => ['code' => 0],
        ];

        $this->post(route('inbound.zoho.disconnect'), ['pms_client_id' => self::PMS_CLIENT_ID])
            ->assertRedirectContains(rawurlencode('1 workflow rule(s) and 1 webhook(s) were removed'));

        $this->assertContains('/settings/webhooks/wh-update', $this->sent('DELETE'));
    }

    public function test_keeps_the_webhooks_when_another_client_uses_the_same_organization(): void
    {
        $this->connection('zoho');
        $this->connection('zoho', self::OTHER_CLIENT_ID);

        $this->post(route('inbound.zoho.disconnect'), ['pms_client_id' => self::PMS_CLIENT_ID])
            ->assertRedirectContains(rawurlencode('another client uses the same Zoho Books organization'));

        $this->assertSame([], $this->sent('GET'));
        $this->assertSame([], $this->sent('DELETE'));
        $this->assertSame([self::OTHER_CLIENT_ID], PmsConnection::query()->pluck('pms_client_id')->all());
    }

    public function test_zoho_crm_disconnect_only_revokes_and_deletes(): void
    {
        $this->connection('zohocrm');

        $this->post(route('inbound.zohocrm.disconnect'), ['pms_client_id' => self::PMS_CLIENT_ID])
            ->assertRedirectContains('/inbound/zohocrm')
            ->assertRedirectContains(rawurlencode('Zoho CRM disconnected'));

        $this->assertSame([], $this->sent('GET'));
        $this->assertSame([], $this->sent('DELETE'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/oauth/v2/token/revoke'));
        $this->assertSame(0, PmsConnection::query()->count());
    }

    public function test_a_failed_revoke_still_deletes_the_connection(): void
    {
        $this->connection('zohocrm');
        Http::fake(['*' => Http::response(['error' => 'invalid'], 500)]);

        $this->post(route('inbound.zohocrm.disconnect'), ['pms_client_id' => self::PMS_CLIENT_ID])
            ->assertRedirectContains(rawurlencode('Zoho CRM disconnected'));

        $this->assertSame(0, PmsConnection::query()->count());
    }

    private function connection(string $provider, string $pmsClientId = self::PMS_CLIENT_ID): PmsConnection
    {
        return PmsConnection::query()->create([
            'provider' => $provider,
            'pms_client_id' => $pmsClientId,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'token_expires_at' => now()->addHour(),
            'meta' => $provider === 'zoho'
                ? ['default_organization_id' => '777', 'webhook_id' => 'wh-create', 'workflow_id' => 'wf-created']
                : ['api_domain' => 'https://www.zohoapis.com'],
        ]);
    }

    /** @return list<string> Books paths called with this method, in order. */
    private function sent(string $method): array
    {
        return Http::recorded()
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $r) => $r->method() === $method && str_contains($r->url(), self::BOOKS))
            ->map(fn (Request $r) => substr((string) parse_url($r->url(), PHP_URL_PATH), strlen(self::BOOKS)))
            ->values()
            ->all();
    }
}
