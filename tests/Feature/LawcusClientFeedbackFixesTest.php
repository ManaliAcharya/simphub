<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\LawcusConnection;
use Modules\Inbound\Services\LawcusInvoiceIngestionService;
use Modules\Inbound\Services\LawcusMatterActivityService;
use Modules\Payment\Services\EmailAmount;
use Modules\Payment\Services\PaymentEmailTemplate;
use Modules\Payment\Services\InvoiceLiveStatusResolver;
use Modules\Payment\Services\InvoicePdfService;
use Tests\TestCase;

class LawcusClientFeedbackFixesTest extends TestCase
{
    use RefreshDatabase;

    private function trustRequest(): Invoice
    {
        config()->set('services.lawcus.rest_api_base_url', 'https://api.us.lawcus.com');

        LawcusConnection::query()->create([
            'provider'      => 'lawcus',
            'pms_client_id' => 'firm-1',
            'access_token'  => 'api-token',
        ]);

        return Invoice::query()->forceCreate([
            'pms_source'          => 'lawcus',
            'pms_client_id'       => 'firm-1',
            'external_invoice_id' => 'tr-uuid-1',
            'external_client_id'  => 'contact-1',
            'amount_cents'        => 300,
            'currency'            => 'USD',
            'fund_type'           => 'TRUST',
            'status'              => 'APPROVED',
        ]);
    }

    private function fakeLawcus(array $listRow): void
    {
        Http::fake([
            'https://api.us.lawcus.com/invoices/*' => Http::response([
                // The single-invoice read still shows the request as owed.
                'invoice'  => ['id' => 'tr-uuid-1', 'type' => 'TRUST', 'status' => 'APPROVED', 'amount_due' => '3.00', 'total' => '3.00', 'created_at' => now()->utc()->format('Y-m-d H:i:s')],
                'payments' => [['id' => 'p1']],
            ]),
            'https://api.us.lawcus.com/v2/invoices' => Http::response([
                'list' => [$listRow + ['id' => 'tr-uuid-1', 'created_at' => now()->utc()->format('Y-m-d H:i:s')]],
            ]),
        ]);
    }

    public function test_a_trust_request_the_lawcus_list_shows_as_paid_is_not_emailed(): void
    {
        $invoice = $this->trustRequest();
        $this->fakeLawcus(['status' => 'PAID', 'amount_due' => '0.00']);

        $this->assertSame('paid', app(InvoiceLiveStatusResolver::class)->resolve($invoice));
    }

    public function test_a_trust_request_still_owed_in_the_list_is_unpaid(): void
    {
        $invoice = $this->trustRequest();
        $this->fakeLawcus(['status' => 'APPROVED', 'amount_due' => '3.00']);

        $this->assertSame('unpaid', app(InvoiceLiveStatusResolver::class)->resolve($invoice));
    }

    public function test_a_trust_request_with_its_amount_recorded_as_paid_is_not_emailed(): void
    {
        $invoice = $this->trustRequest();

        Http::fake([
            'https://api.us.lawcus.com/invoices/*' => Http::response([
                // Status and amount due not yet updated, but the $3 payment is on it.
                'invoice'  => ['id' => 'tr-uuid-1', 'type' => 'TRUST', 'status' => 'APPROVED', 'amount_due' => '3.00', 'total' => '3.00', 'amount_paid' => null],
                'payments' => [['id' => 'p1', 'credit' => '3.00', 'transaction_type' => 'INVOICE_PAYMENT']],
            ]),
        ]);

        $this->assertSame('paid', app(InvoiceLiveStatusResolver::class)->resolve($invoice));
    }

    /** Field names as Lawcus returns them (GET /invoices/{id}, probed 2026-11-23). */
    public function test_pdf_lists_lawcus_time_entries_flat_fees_and_expenses(): void
    {
        $items = InvoicePdfService::lawcusLineItems([
            'timeentries' => [
                ['description' => '<p>Draft deed</p>', 'quantity' => 1.5, 'rate' => 250, 'total' => 375, 'date' => '2026-11-20T08:37:26', 'activity_id' => null],
                ['description' => 'Internal call', 'quantity' => 1, 'rate' => 250, 'total' => 250, 'is_non_billable' => true],
            ],
            'flatfees' => [['description' => null, 'quantity' => 1, 'rate' => 500, 'total' => 500, 'activity_id' => 11]],
            'expenses' => [['public_note' => 'County recording', 'private_note' => 'internal only', 'quantity' => 1, 'rate' => 7, 'total' => 7, 'activity_id' => 42]],
        ], ['11' => 'Closing Package', '42' => 'Recording Fee']);

        $this->assertCount(3, $items);
        $this->assertSame(['Time entry', 'Draft deed', 1.5, 250.0, 375.0], [$items[0]['productOrService'], $items[0]['description'], $items[0]['qty'], $items[0]['rate'], $items[0]['amount']]);
        $this->assertSame('11/20/2026', $items[0]['subDescription']);
        $this->assertSame(['Closing Package', 'Closing Package'], [$items[1]['productOrService'], $items[1]['description']]);
        $this->assertSame(['Recording Fee', 'County recording', 7.0], [$items[2]['productOrService'], $items[2]['description'], $items[2]['amount']]);
        $this->assertStringNotContainsString('internal only', json_encode($items));
        $this->assertSame([], InvoicePdfService::lawcusLineItems([]));
    }

    public function test_an_expense_in_the_recording_fee_category_picks_the_recording_fee_template(): void
    {
        $invoice = fn (array $expenses, array $activities, string $fundType = 'OPERATING') => (new Invoice)->forceFill([
            'pms_source'  => 'lawcus',
            'fund_type'   => $fundType,
            'raw_payload' => ['invoice' => ['data' => ['timeentries' => [], 'flatfees' => [], 'expenses' => $expenses]], 'activities' => $activities],
        ]);
        $fee = ['activity_id' => 42, 'quantity' => 1, 'rate' => 75, 'total' => 75];

        $this->assertSame(PaymentEmailTemplate::RECORDING_FEE, PaymentEmailTemplate::kindFor($invoice([$fee], ['42' => 'Recording Fee'])));
        $this->assertSame(PaymentEmailTemplate::RECORDING_FEE, PaymentEmailTemplate::kindFor($invoice([$fee], ['42' => ' recording fee '])));
        // Any other category, an unnamed one, or a non-billable recording fee keeps the usual rules.
        $this->assertSame(PaymentEmailTemplate::BILLABLE, PaymentEmailTemplate::kindFor($invoice([$fee], ['42' => 'Filing Fee'])));
        $this->assertSame(PaymentEmailTemplate::BILLABLE, PaymentEmailTemplate::kindFor($invoice([$fee], [])));
        $this->assertSame(PaymentEmailTemplate::BILLABLE, PaymentEmailTemplate::kindFor($invoice([['is_non_billable' => true] + $fee], ['42' => 'Recording Fee'])));
        // Trust Requests stay retainer requests.
        $this->assertSame(PaymentEmailTemplate::RETAINER, PaymentEmailTemplate::kindFor($invoice([$fee], ['42' => 'Recording Fee'], 'TRUST')));
    }

    public function test_ingestion_names_the_lines_activity_categories(): void
    {
        Mail::fake();
        config()->set('services.lawcus.rest_api_base_url', 'https://api.us.lawcus.com');

        $client = Client::query()->forceCreate([
            'id' => (string) Str::uuid(), 'pms_client_id' => (string) Str::uuid(), 'client_name' => 'RR Law', 'client_pms' => 'LAWCUS',
        ]);
        LawcusConnection::query()->create(['provider' => 'lawcus', 'pms_client_id' => $client->pms_client_id, 'access_token' => 'api-token']);

        Http::fake([
            'https://api.us.lawcus.com/invoices/*' => Http::response(['invoice' => [
                'id' => 'inv-rf', 'number' => '01800', 'status' => 'APPROVED', 'amount_due' => '75.00', 'total' => '75.00',
                'client' => ['email' => 'client@example.com', 'name' => 'Jane Client'],
                'timeentries' => [], 'flatfees' => [],
                'expenses' => [['activity_id' => 'ba77a470-59c3-11ef-8ffe-3df61087b372', 'quantity' => 1, 'rate' => 75, 'total' => 75, 'public_note' => 'County recording']],
            ], 'payments' => []]),
            // RR Law's list as Lawcus returns it (GET /activities, 2026-11-23): a bare array of {id, name}.
            'https://api.us.lawcus.com/activities' => Http::response([['id' => 'ba77a470-59c3-11ef-8ffe-3df61087b372', 'name' => 'Recording Fee'], ['id' => '40c5a70-6fea-11f1-9214-dfd6fdcd8777', 'name' => 'Real Estate']]),
        ]);

        $result = app(LawcusInvoiceIngestionService::class)->ingest('inv-rf', ['pms_client_id' => $client->pms_client_id, 'event_name' => 'poll.invoice.saved']);

        $this->assertSame(['ba77a470-59c3-11ef-8ffe-3df61087b372' => 'Recording Fee'], $result['invoice']->raw_payload['activities']);
        $this->assertSame(PaymentEmailTemplate::RECORDING_FEE, PaymentEmailTemplate::kindFor($result['invoice']));
    }

    public function test_matter_activity_is_posted_as_a_secure_message_unless_configured_back_to_phone(): void
    {
        $this->assertSame('SECURE_MESSAGE', LawcusMatterActivityService::interactionType());

        config()->set('services.lawcus.activity_interaction_type', 'phone');
        $this->assertSame('PHONE', LawcusMatterActivityService::interactionType());

        config()->set('services.lawcus.activity_interaction_type', 'EMAIL');
        $this->assertSame('SECURE_MESSAGE', LawcusMatterActivityService::interactionType());
    }

    public function test_amount_placeholder_reads_as_dollars(): void
    {
        $this->assertSame('$8.00', EmailAmount::format(800, 'USD'));
        $this->assertSame('$1,234.50', EmailAmount::format(123450, null));
        $this->assertSame('8.00 CAD', EmailAmount::format(800, 'cad'));

        $data = ['amount' => '$8.00', 'invoice_number' => '01748'];
        $this->assertSame('Please pay $8.00.', EmailAmount::fill('Please pay ${amount}.', $data));
        $this->assertSame('Please pay $8.00 for #01748.', EmailAmount::fill('Please pay {amount} for #{invoice_number}.', $data));
    }
}
