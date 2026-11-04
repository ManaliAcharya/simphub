<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClientMidRoute;
use Modules\Inbound\Models\ClioConnection;
use Modules\Inbound\Services\ClioInvoiceIngestionService;
use Modules\Payment\Services\PaymentCheckoutService;
use Modules\Payment\Services\QbFeeOverride;
use Tests\TestCase;

/** Clio Multi-MID: a matter custom field picks the Fees On / Fees Off MID, as QuickBooks does. */
class ClioMultiMidTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_picks_the_mid_by_the_matter_field_and_fund_type(): void
    {
        $client = $this->clioClient();

        foreach (['OPERATING', 'TRUST'] as $fundType) {
            foreach (['fees_on', 'fees_off'] as $routeType) {
                ClientMidRoute::query()->create([
                    'client_id' => $client->id,
                    'fund_type' => $fundType,
                    'route_type' => $routeType,
                    'gateway' => 'fluidpay',
                    'mid_identifier' => "MID-{$fundType}-{$routeType}",
                ]);
            }
        }

        $cases = [
            ['OPERATING', 'Yes', 'MID-OPERATING-fees_on'],
            ['OPERATING', null, 'MID-OPERATING-fees_off'],
            ['TRUST', 'Yes', 'MID-TRUST-fees_on'],
            ['TRUST', 'No', 'MID-TRUST-fees_off'],
        ];

        foreach ($cases as [$fundType, $value, $expectedMid]) {
            $invoice = $this->clioInvoice($fundType, $value === null ? null : ['field_name' => 'Pass Fees', 'value' => $value]);

            $this->assertSame($expectedMid, $this->resolveMidRoute($invoice, $client)?->mid_identifier, "{$fundType} / ".var_export($value, true));
        }
    }

    public function test_trust_request_never_falls_back_to_an_operating_mid(): void
    {
        $client = $this->clioClient();

        ClientMidRoute::query()->create([
            'client_id' => $client->id,
            'route_type' => 'fees_on',
            'gateway' => 'fluidpay',
            'mid_identifier' => 'MID-OPERATING',
        ]);

        $trust = $this->clioInvoice('TRUST', ['field_name' => 'Pass Fees', 'value' => 'Yes']);

        // No TRUST route → no override; the IOLTA MID in Gateway Credentials applies as before.
        $this->assertNull($this->resolveMidRoute($trust, $client));
    }

    public function test_only_clio_clients_save_iolta_routes(): void
    {
        foreach (['CLIO' => 'TRUST', 'QUICKBOOKS' => 'OPERATING'] as $pms => $expected) {
            $client = $this->clioClient($pms);

            $this->withoutMiddleware()->post(route('inbound.clients.save-mid-routes', $client->pms_client_id), [
                'qb_multi_mid_enabled' => '1',
                'routes' => ['trust_fees_on_nmi' => [
                    'route_type' => 'fees_on',
                    'fund_type' => 'TRUST',
                    'gateway' => 'nmi',
                    'mid_identifier' => 'MID-IOLTA',
                ]],
            ]);

            $this->assertSame($expected, ClientMidRoute::query()->where('client_id', $client->id)->value('fund_type'), $pms);
        }
    }

    public function test_ingestion_fetches_the_matter_and_stores_its_custom_fields(): void
    {
        $client = $this->clioClient();
        $this->fakeClio($client, 'revenue_kind', Http::response(['data' => [
            'id' => 77,
            'custom_field_values' => [['id' => 'text_line-1', 'field_name' => 'Pass Fees', 'field_type' => 'text_line', 'value' => 'Yes']],
        ]]));

        $invoice = app(ClioInvoiceIngestionService::class)
            ->ingest('123', ['pms_client_id' => $client->pms_client_id])['invoice'];

        $this->assertSame('Yes', QbFeeOverride::fieldValue($invoice, 'Pass Fees'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v4/matters/77.json'));
    }

    public function test_ingestion_fetches_the_matter_of_a_trust_request(): void
    {
        $client = $this->clioClient();
        $this->fakeClio($client, 'trust_kind', Http::response(['data' => [
            'id' => 77,
            'custom_field_values' => [['field_name' => 'Pass Fees', 'field_type' => 'checkbox', 'value' => true]],
        ]]));

        $invoice = app(ClioInvoiceIngestionService::class)
            ->ingest('123', ['pms_client_id' => $client->pms_client_id])['invoice'];

        $this->assertSame('TRUST', $invoice->fund_type);
        $this->assertSame('Yes', QbFeeOverride::fieldValue($invoice, 'Pass Fees'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v4/matters/77.json'));
    }

    public function test_ingestion_still_succeeds_when_the_matter_fetch_fails(): void
    {
        $client = $this->clioClient();
        $this->fakeClio($client, 'revenue_kind', Http::response(['error' => ['type' => 'ServerError']], 500));

        $invoice = app(ClioInvoiceIngestionService::class)
            ->ingest('123', ['pms_client_id' => $client->pms_client_id])['invoice'];

        $this->assertNotNull($invoice);
        $this->assertNull(QbFeeOverride::fieldValue($invoice, 'Pass Fees'));
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'CLIO_MATTER_FETCH_FAILED']);
    }

    private function clioInvoice(string $fundType, ?array $matterField, ?array $contactField = null): Invoice
    {
        return (new Invoice)->forceFill([
            'pms_source' => 'clio',
            'fund_type' => $fundType,
            'raw_payload' => [
                'matter' => ['data' => ['custom_field_values' => $matterField === null ? [] : [$matterField]]],
                'customer' => ['data' => ['custom_field_values' => $contactField === null ? [] : [$contactField]]],
            ],
        ]);
    }

    private function clioClient(string $pms = 'CLIO'): Client
    {
        return Client::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'pms_client_id' => (string) Str::uuid(),
            'client_name' => 'RR Law',
            'client_pms' => $pms,
            'qb_fee_override_enabled' => true,
            'qb_fee_override_field' => 'Pass Fees',
            'qb_fee_override_blank_default' => QbFeeOverride::BLANK_ABSORB,
            'qb_multi_mid_enabled' => true,
        ]);
    }

    private function fakeClio(Client $client, string $kind, $matterResponse): void
    {
        Mail::fake();
        config()->set('services.clio.api_base_url', 'https://app.clio.com');

        ClioConnection::query()->create([
            'provider' => 'clio',
            'pms_client_id' => $client->pms_client_id,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'https://app.clio.com/api/v4/bills/123.json*' => Http::response(['data' => [
                'id' => 123,
                'kind' => $kind,
                'state' => 'draft',
                'total' => '500.00',
                'currency' => 'usd',
                'client' => ['id' => 'client-9'],
                'matters' => [['id' => 77]],
            ]]),
            'https://app.clio.com/api/v4/matters/*' => $matterResponse,
            'https://app.clio.com/api/v4/contacts/*' => Http::response(['data' => ['id' => 'client-9', 'custom_field_values' => []]]),
        ]);
    }

    private function resolveMidRoute(Invoice $invoice, Client $client): ?ClientMidRoute
    {
        $method = new \ReflectionMethod(PaymentCheckoutService::class, 'resolveQbMidRoute');

        return $method->invoke(app(PaymentCheckoutService::class), $invoice, $client, 'FluidPay');
    }
}
