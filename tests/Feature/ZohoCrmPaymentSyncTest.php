<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Models\Subscription;
use Modules\Inbound\Services\SubscriptionService;
use Modules\Inbound\Services\ZohoCrmPaymentSyncService;
use Tests\TestCase;

class ZohoCrmPaymentSyncTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://www.zohoapis.com/crm/v8';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'pms_client_id' => (string) Str::uuid(),
            'client_name' => 'Acme',
            'client_pms' => 'ZOHOCRM',
            'call_api_to_pms' => true,
        ]);
    }

    public function test_creates_missing_fields_and_updates_contact_by_zoho_id(): void
    {
        $connection = $this->connection();
        [$invoice, $subscription] = $this->subscriptionInvoice('5725767000000412001');

        Http::fake([
            self::API.'/settings/fields*' => Http::sequence()
                ->push(['fields' => [['field_label' => 'Email', 'api_name' => 'Email']]])
                ->push(['fields' => [['code' => 'SUCCESS', 'status' => 'success']]])
                ->push(['fields' => [['code' => 'SUCCESS', 'status' => 'success']]])
                ->push(['fields' => [['code' => 'SUCCESS', 'status' => 'success']]])
                ->push(['fields' => [
                    ['field_label' => 'SimpHub ID', 'api_name' => 'SimpHub_ID'],
                    ['field_label' => 'SimpHub Payment Link', 'api_name' => 'SimpHub_Payment_Link'],
                    ['field_label' => 'SimpHub Last Payment Date', 'api_name' => 'SimpHub_Last_Payment_Date'],
                ]]),
            self::API.'/Contacts/5725767000000412001?*' => Http::response(['data' => [['id' => '5725767000000412001']]]),
            self::API.'/Contacts/5725767000000412001' => Http::response(['data' => [['code' => 'SUCCESS', 'status' => 'success']]]),
        ]);

        $synced = app(ZohoCrmPaymentSyncService::class)->sync($this->transaction(), $invoice);

        $this->assertTrue($synced);
        $this->assertSame('SYNCED', $invoice->fresh()->pms_sync_status);
        $this->assertSame('SimpHub_ID', data_get($connection->fresh()->meta, 'contact_fields.simphub_id'));

        Http::assertSent(function (Request $request) use ($subscription) {
            if ($request->method() !== 'PUT') {
                return false;
            }

            $fields = $request->data()['data'][0];

            return $fields['SimpHub_ID'] === $subscription->reference_id
                && str_contains($fields['SimpHub_Payment_Link'], '/')
                && $fields['SimpHub_Last_Payment_Date'] === '2026-10-14';
        });
    }

    public function test_writes_to_configured_existing_fields_without_creating_any(): void
    {
        config(['services.zohocrm.contact_fields' => [
            'simphub_id' => 'Department',
            'payment_link' => 'Description',
            'last_payment_date' => 'Date_of_Birth',
        ]]);

        $this->connection();
        [$invoice, $subscription] = $this->subscriptionInvoice('5725767000000412001');

        Http::fake([
            self::API.'/Contacts/5725767000000412001?*' => Http::response(['data' => [['id' => '5725767000000412001']]]),
            self::API.'/Contacts/5725767000000412001' => Http::response(['data' => [['code' => 'SUCCESS', 'status' => 'success']]]),
        ]);

        $this->assertTrue(app(ZohoCrmPaymentSyncService::class)->sync($this->transaction(), $invoice));

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/settings/fields'));
        Http::assertSent(function (Request $r) use ($subscription) {
            $fields = $r->data()['data'][0] ?? [];

            return $r->method() === 'PUT'
                && $fields['Department'] === $subscription->reference_id
                && str_contains($fields['Description'], '/')
                && $fields['Date_of_Birth'] === '2026-10-14';
        });
    }

    public function test_falls_back_to_email_and_uses_name_when_several_contacts_share_it(): void
    {
        $this->connection(withFields: true);
        [$invoice] = $this->subscriptionInvoice('CUST-1');

        Http::fake([
            self::API.'/Contacts/search*' => Http::response(['data' => [
                ['id' => '111', 'Full_Name' => 'Someone Else'],
                ['id' => '222', 'Full_Name' => 'Jane Doe'],
            ]]),
            self::API.'/Contacts/222' => Http::response(['data' => [['code' => 'SUCCESS', 'status' => 'success']]]),
        ]);

        $this->assertTrue(app(ZohoCrmPaymentSyncService::class)->sync($this->transaction(), $invoice));
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/Contacts/222'));
    }

    public function test_marks_invoice_failed_when_no_contact_matches(): void
    {
        $this->connection(withFields: true);
        [$invoice] = $this->subscriptionInvoice(null);

        Http::fake([self::API.'/Contacts/search*' => Http::response(null, 204)]);

        $this->assertFalse(app(ZohoCrmPaymentSyncService::class)->sync($this->transaction(), $invoice));
        $this->assertSame('FAILED', $invoice->fresh()->pms_sync_status);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    }

    public function test_does_nothing_when_zoho_crm_is_not_connected(): void
    {
        [$invoice] = $this->subscriptionInvoice(null);
        Http::fake();

        $this->assertFalse(app(ZohoCrmPaymentSyncService::class)->sync($this->transaction(), $invoice));
        $this->assertSame('SYNCED', $invoice->fresh()->pms_sync_status);
        Http::assertNothingSent();
    }

    private function connection(bool $withFields = false): PmsConnection
    {
        return PmsConnection::query()->create([
            'provider' => 'zohocrm',
            'pms_client_id' => $this->client->pms_client_id,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'token_expires_at' => now()->addHour(),
            'meta' => array_filter([
                'api_domain' => 'https://www.zohoapis.com',
                'contact_fields' => $withFields ? [
                    'simphub_id' => 'SimpHub_ID',
                    'payment_link' => 'SimpHub_Payment_Link',
                    'last_payment_date' => 'SimpHub_Last_Payment_Date',
                ] : null,
            ]),
        ]);
    }

    /** @return array{0: Invoice, 1: Subscription} */
    private function subscriptionInvoice(?string $uniqueId): array
    {
        $subscription = app(SubscriptionService::class)->subscribe($this->client, [
            'customer_email' => 'jane@example.com',
            'customer_name' => 'Jane Doe',
            'customer_unique_id' => $uniqueId,
            'subscription_amount' => '49.00',
        ])['subscription'];

        $invoice = Invoice::query()->where('pms_source', 'zohocrm')->latest('created_at')->firstOrFail();

        return [$invoice, $subscription];
    }

    private function transaction(): Transaction
    {
        return (new Transaction)->forceFill([
            'id' => (string) Str::uuid(),
            'created_at' => '2026-10-14 10:00:00',
        ]);
    }
}
