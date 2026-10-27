<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClientMidRoute;
use Modules\Inbound\Models\LawcusConnection;
use Modules\Inbound\Services\LawcusInvoiceIngestionService;
use Modules\Payment\Services\PaymentCheckoutService;
use Modules\Payment\Services\QbFeeOverride;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LawcusMultiMidTest extends TestCase
{
    use RefreshDatabase;

    private const MATTER_UUID = '00000000-0000-4000-8000-000000000001';

    public function test_checkout_picks_the_mid_by_matter_field_and_fund_type(): void
    {
        $client = $this->lawcusClient();

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
            ['TRUST', 'Yes', 'MID-TRUST-fees_on'],
            ['TRUST', 'No', 'MID-TRUST-fees_off'],
            ['OPERATING', 'Yes', 'MID-OPERATING-fees_on'],
            ['OPERATING', null, 'MID-OPERATING-fees_off'],
        ];

        foreach ($cases as [$fundType, $value, $expectedMid]) {
            $invoice = (new Invoice)->forceFill([
                'pms_source' => 'lawcus',
                'fund_type' => $fundType,
                'raw_payload' => ['matter' => ['custom_fields' => $value === null ? [] : [
                    ['name' => 'Pass Fees', 'value' => $value],
                ]]],
            ]);

            $this->assertSame(
                $expectedMid,
                $this->resolveMidRoute($invoice, $client)?->mid_identifier,
                "{$fundType} / ".var_export($value, true),
            );
        }
    }

    public function test_trust_request_never_falls_back_to_an_operating_mid(): void
    {
        $client = $this->lawcusClient();

        ClientMidRoute::query()->create([
            'client_id' => $client->id,
            'route_type' => 'fees_on',
            'gateway' => 'fluidpay',
            'mid_identifier' => 'MID-OPERATING',
        ]);

        $trust = (new Invoice)->forceFill([
            'pms_source' => 'lawcus',
            'fund_type' => 'TRUST',
            'raw_payload' => ['matter' => ['custom_fields' => [['name' => 'Pass Fees', 'value' => 'Yes']]]],
        ]);

        // No TRUST route → no override; the trust routing rule (IOLTA MID) applies as before.
        $this->assertNull($this->resolveMidRoute($trust, $client));
    }

    public function test_ingestion_fetches_the_matter_and_stores_its_custom_fields(): void
    {
        $client = $this->lawcusClient();
        $this->fakeLawcus($client, Http::response(['matter' => [
            'uuid' => self::MATTER_UUID,
            'custom_fields' => [['type' => 'TEXT', 'name' => 'Pass Fees', 'value' => 'Yes']],
        ]]));

        $invoice = app(LawcusInvoiceIngestionService::class)
            ->ingest('inv-1', ['pms_client_id' => $client->pms_client_id])['invoice'];

        $this->assertSame('TRUST', $invoice->fund_type);
        $this->assertSame('Yes', QbFeeOverride::fieldValue($invoice, 'Pass Fees'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/matters/'.self::MATTER_UUID));
    }

    public function test_ingestion_still_succeeds_when_the_matter_fetch_fails(): void
    {
        $client = $this->lawcusClient();
        $this->fakeLawcus($client, Http::response(['message' => 'Server error'], 500));

        $invoice = app(LawcusInvoiceIngestionService::class)
            ->ingest('inv-1', ['pms_client_id' => $client->pms_client_id])['invoice'];

        $this->assertNull(QbFeeOverride::fieldValue($invoice, 'Pass Fees'));
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'LAWCUS_MATTER_FETCH_FAILED']);
    }

    private function lawcusClient(): Client
    {
        return Client::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'pms_client_id' => (string) Str::uuid(),
            'client_name' => 'RR Law',
            'client_pms' => 'LAWCUS',
            'qb_fee_override_enabled' => true,
            'qb_fee_override_field' => 'Pass Fees',
            'qb_fee_override_blank_default' => QbFeeOverride::BLANK_ABSORB,
            'qb_multi_mid_enabled' => true,
        ]);
    }

    private function fakeLawcus(Client $client, $matterResponse): void
    {
        Mail::fake();
        config()->set('services.lawcus.rest_api_base_url', 'https://api.us.lawcus.test');

        LawcusConnection::query()->forceCreate([
            'provider' => 'lawcus',
            'pms_client_id' => $client->pms_client_id,
            'access_token' => 'test-token',
        ]);

        Http::fake([
            'https://api.us.lawcus.test/invoices/*' => Http::response(['invoice' => [
                'id' => 'inv-1',
                'number' => '1001',
                'type' => 'TRUST',
                'status' => 'SENT',
                'amount_due' => '250.00',
                'matter' => ['id' => 1949408, 'uuid' => self::MATTER_UUID],
                'client' => ['id' => 77, 'name' => 'Pat Example', 'email' => 'pat@example.com'],
            ], 'payments' => []]),
            'https://api.us.lawcus.test/matters/*' => $matterResponse,
        ]);
    }

    private function resolveMidRoute(Invoice $invoice, Client $client): ?ClientMidRoute
    {
        $method = new \ReflectionMethod(PaymentCheckoutService::class, 'resolveQbMidRoute');

        return $method->invoke(app(PaymentCheckoutService::class), $invoice, $client, 'FluidPay');
    }
}
