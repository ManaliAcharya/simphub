<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClioConnection;
use Modules\Inbound\Services\ClioBillRules;
use Modules\Inbound\Services\ClioInvoiceIngestionService;
use Modules\Inbound\Services\ClioLiveBalanceService;
use Modules\Inbound\Services\MissingRecipientAlertService;
use Modules\Payment\Services\InvoiceListStatus;
use Modules\Payment\Services\PaymentLinkService;
use Tests\TestCase;

/** Clio: LEDES (insurer-billed) skip, live balance on the payment link, no-email alert, PDF figures. */
class ClioLedesBalanceAndPdfTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://app.clio.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.clio.api_base_url' => self::API, 'services.clio.approved_bill_states' => ['AWAITING_PAYMENT']]);
    }

    // --- LEDES rules ----------------------------------------------------------------------

    public function test_clios_native_ledes_setting_alone_does_not_skip(): void
    {
        // Firms keep it on for matters billed to both an insurer and the client.
        $this->assertNull(ClioBillRules::ledesSkipReason([['require_utbms_codes' => true]]));
        $this->assertNull(ClioBillRules::ledesSkipReason([['require_utbms_codes' => true, 'custom_field_values' => [
            ['field_name' => 'LEDES', 'field_type' => 'checkbox', 'value' => false],
        ]]]));
    }

    public function test_ledes_picklist_skips_only_on_yes(): void
    {
        $matter = fn (?string $option, int $id = 501) => [['custom_field_values' => [[
            'field_name' => 'LEDES', 'field_type' => 'picklist', 'value' => $id,
            'picklist_option' => $option === null ? null : ['id' => $id, 'option' => $option],
        ]]]];

        $this->assertSame('ledes_custom_field', ClioBillRules::ledesSkipReason($matter('Yes')));
        $this->assertNull(ClioBillRules::ledesSkipReason($matter('No', 502)), 'the option id must not read as Yes');
        $this->assertNull(ClioBillRules::ledesSkipReason($matter(null)));
    }

    public function test_ledes_checkbox_custom_field_skips_even_with_the_native_setting_off(): void
    {
        $matter = fn ($value, $name = 'LEDES') => [['require_utbms_codes' => false, 'custom_field_values' => [['field_name' => $name, 'value' => $value]]]];

        $this->assertSame('ledes_custom_field', ClioBillRules::ledesSkipReason($matter(true)));
        $this->assertSame('ledes_custom_field', ClioBillRules::ledesSkipReason($matter('true', ' ledes ')));
        $this->assertNull(ClioBillRules::ledesSkipReason($matter(false)));
        $this->assertNull(ClioBillRules::ledesSkipReason($matter(null)));
        $this->assertNull(ClioBillRules::ledesSkipReason($matter(true, 'Pass Fees')));
    }

    public function test_any_ledes_matter_on_a_multi_matter_bill_skips(): void
    {
        $this->assertSame('ledes_custom_field', ClioBillRules::ledesSkipReason([
            ['custom_field_values' => []],
            ['custom_field_values' => [['field_name' => 'LEDES', 'value' => true]]],
        ]));
    }

    // --- Balance rules --------------------------------------------------------------------

    public function test_amount_due_is_the_balance_and_closed_bills_are_recognised(): void
    {
        $this->assertSame(300.0, ClioBillRules::amountDue(['total' => '500.00', 'balance' => '300.00']));
        $this->assertSame(500.0, ClioBillRules::amountDue(['total' => '500.00']));

        $this->assertSame('paid', ClioBillRules::closedReason(['state' => 'paid']));
        $this->assertSame('paid', ClioBillRules::closedReason(['state' => 'awaiting_payment', 'balance' => 0]));
        $this->assertSame('voided', ClioBillRules::closedReason(['state' => 'void', 'balance' => 500]));
        $this->assertSame('voided', ClioBillRules::closedReason(['state' => 'deleted']));
        $this->assertNull(ClioBillRules::closedReason(['state' => 'awaiting_payment', 'balance' => '10.00']));
    }

    // --- Live balance on the payment link -------------------------------------------------

    public function test_live_balance_follows_trust_funds_applied_in_clio(): void
    {
        [$invoice, $session] = $this->clioBill();
        $this->fakeBill(['state' => 'awaiting_payment', 'total' => '500.00', 'paid' => '200.00', 'balance' => '300.00']);

        $this->assertSame(ClioLiveBalanceService::PAYABLE, app(ClioLiveBalanceService::class)->sync($invoice, $session));
        $this->assertSame(30000, $invoice->fresh()->amount_cents);
        $this->assertSame('active', $session->fresh()->link_status);
    }

    public function test_a_bill_fully_covered_in_clio_can_no_longer_be_paid(): void
    {
        [$invoice, $session] = $this->clioBill();
        $this->fakeBill(['state' => 'paid', 'total' => '500.00', 'paid' => '500.00', 'balance' => '0.00']);

        $this->assertSame(ClioLiveBalanceService::CLOSED, app(ClioLiveBalanceService::class)->sync($invoice, $session));
        $this->assertSame('paid', $session->fresh()->link_status);
        $this->assertSame(50000, $invoice->fresh()->amount_cents);
    }

    public function test_a_voided_or_deleted_bill_closes_its_link(): void
    {
        [$invoice, $session] = $this->clioBill();
        $this->fakeBill(['state' => 'void', 'total' => '500.00', 'balance' => '500.00']);

        $this->assertSame(ClioLiveBalanceService::CLOSED, app(ClioLiveBalanceService::class)->sync($invoice, $session));
        $this->assertSame('voided', $session->fresh()->link_status);

        [$invoice2, $session2] = $this->clioBill('124');
        Http::fake([self::API.'/api/v4/bills/124.json*' => Http::response(['error' => []], 404)]);

        app(ClioLiveBalanceService::class)->sync($invoice2, $session2);
        $this->assertSame('voided', $session2->fresh()->link_status);
    }

    public function test_an_unreachable_clio_changes_nothing(): void
    {
        [$invoice, $session] = $this->clioBill();
        Http::fake([self::API.'/*' => Http::response('', 503)]);

        $this->assertSame(ClioLiveBalanceService::UNKNOWN, app(ClioLiveBalanceService::class)->sync($invoice, $session));
        $this->assertSame(50000, $invoice->fresh()->amount_cents);
        $this->assertSame('active', $session->fresh()->link_status);
    }

    public function test_payment_page_refuses_a_bill_covered_since_the_link_went_out(): void
    {
        [, $session] = $this->clioBill();
        $this->fakeBill(['state' => 'paid', 'total' => '500.00', 'balance' => '0.00']);

        $this->expectExceptionMessage('This invoice has already been paid.');

        app(\Modules\Payment\Services\PaymentCheckoutService::class)->details($session);
    }

    public function test_submit_refuses_when_the_balance_moved_since_the_page_loaded(): void
    {
        [, $session] = $this->clioBill();
        $this->fakeBill(['state' => 'awaiting_payment', 'total' => '500.00', 'balance' => '300.00']);

        $this->expectExceptionMessage(\Modules\Payment\Services\PaymentCheckoutService::BALANCE_CHANGED_MESSAGE);

        app(\Modules\Payment\Services\PaymentCheckoutService::class)
            ->submit($session, 'tok', 'CARD', 'rule-1', 'debit', [], null, 50000);
    }

    // --- Ingestion ------------------------------------------------------------------------

    public function test_approved_bill_on_a_ledes_matter_sends_nothing_and_shows_skipped(): void
    {
        $client = $this->firm();
        $this->fakeIngestion(['custom_field_values' => [['field_name' => 'LEDES', 'field_type' => 'checkbox', 'value' => true]]]);

        $links = Mockery::mock(PaymentLinkService::class);
        $links->shouldNotReceive('sendInvoiceLinkOnce');
        $this->app->instance(PaymentLinkService::class, $links);

        $result = app(ClioInvoiceIngestionService::class)->ingest('123', ['pms_client_id' => $client->pms_client_id]);

        $this->assertSame(ClioBillRules::SKIP_LEDES, data_get($result['invoice']->raw_payload, 'email_skipped'));
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'PAYMENT_LINK_SKIPPED']);
        $this->assertSame('Skipped', app(InvoiceListStatus::class)->emailStatus($result['invoice'], $result['payment_session'], null)['label']);
    }

    public function test_approved_bill_is_charged_its_balance(): void
    {
        $client = $this->firm();
        $this->fakeIngestion(['custom_field_values' => []], ['total' => '500.00', 'paid' => '150.00', 'balance' => '350.00']);
        $this->app->instance(PaymentLinkService::class, Mockery::mock(PaymentLinkService::class)->shouldReceive('sendInvoiceLinkOnce')->andReturn(1)->getMock());
        Queue::fake();

        $result = app(ClioInvoiceIngestionService::class)->ingest('123', ['pms_client_id' => $client->pms_client_id]);

        $this->assertSame(35000, $result['invoice']->amount_cents);
    }

    public function test_merchant_is_alerted_once_when_the_client_has_no_email(): void
    {
        $client = $this->firm();
        \Modules\Inbound\Models\EmailConfiguration::query()->forceCreate([
            'client_id' => $client->id, 'reply_to_email' => 'billing@firm.test',
        ]);
        $this->fakeIngestion(['custom_field_values' => []], [], withEmail: false);

        $service = app(ClioInvoiceIngestionService::class);
        $service->ingest('123', ['pms_client_id' => $client->pms_client_id]);
        $service->ingest('123', ['pms_client_id' => $client->pms_client_id]);

        $this->assertSame(1, \Modules\Audit\Models\AuditLog::query()->where('event_type', MissingRecipientAlertService::EVENT)->count());
    }

    // --- PDF figures ----------------------------------------------------------------------

    public function test_pdf_uses_clios_totals_and_shows_what_was_applied(): void
    {
        $invoice = (new Invoice)->forceFill([
            'pms_source'   => 'clio',
            'fund_type'    => 'OPERATING',
            'amount_cents' => 30000,
            'raw_payload'  => ['invoice' => ['data' => [
                'subject' => 'Services through October', 'memo' => 'Thank you',
                'sub_total' => '520.00', 'tax_sum' => '10.00', 'secondary_tax_sum' => '0', 'total' => '500.00',
                'matters' => [['id' => 7, 'display_number' => '00042', 'description' => 'Smith v. Jones']],
            ]]],
        ]);

        $view = (new \ReflectionMethod(\Modules\Payment\Services\InvoicePdfService::class, 'clioView'))
            ->invoke(app(\Modules\Payment\Services\InvoicePdfService::class), $invoice);

        $this->assertSame('520.00', $view['subtotal']);
        $this->assertSame('10.00', $view['taxAmount']);
        $this->assertSame('-30.00', $view['adjustmentAmount']);
        $this->assertSame('500.00', $view['invoiceTotal']);
        $this->assertSame('200.00', $view['paidAmount']);
        $this->assertSame('300.00', $view['totalAmount']);
        $this->assertSame('00042 - Smith v. Jones', $view['matterLabel']);
        $this->assertSame('Invoice', $view['documentLabel']);
    }

    public function test_trust_request_pdf_is_titled_as_one(): void
    {
        $invoice = (new Invoice)->forceFill(['pms_source' => 'clio', 'fund_type' => 'TRUST', 'amount_cents' => 100000,
            'raw_payload' => ['invoice' => ['data' => ['total' => '1000.00']]]]);

        $view = (new \ReflectionMethod(\Modules\Payment\Services\InvoicePdfService::class, 'clioView'))
            ->invoke(app(\Modules\Payment\Services\InvoicePdfService::class), $invoice);

        $this->assertSame('Trust Request', $view['documentLabel']);
        $this->assertNull($view['paidAmount']);
        $this->assertSame('1,000.00', $view['totalAmount']);
    }

    // --- Helpers --------------------------------------------------------------------------

    private function firm(): Client
    {
        Mail::fake();

        $client = Client::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'pms_client_id' => (string) Str::uuid(),
            'client_name' => 'Korkin Law',
            'client_pms' => 'CLIO',
        ]);

        ClioConnection::query()->create([
            'provider' => 'clio',
            'pms_client_id' => $client->pms_client_id,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);

        return $client;
    }

    private function fakeIngestion(array $matter, array $amounts = [], bool $withEmail = true): void
    {
        Http::fake([
            self::API.'/api/v4/bills/123.json*' => Http::response(['data' => array_merge([
                'id' => 123, 'number' => '1001', 'kind' => 'revenue_kind', 'state' => 'awaiting_payment',
                'total' => '500.00', 'balance' => '500.00', 'currency' => 'usd',
                'client' => array_filter(['id' => 'client-9', 'name' => 'Pat Doe', 'primary_email_address' => $withEmail ? 'pat@example.com' : null]),
                'matters' => [['id' => 77]],
            ], $amounts)]),
            self::API.'/api/v4/matters/*' => Http::response(['data' => ['id' => 77] + $matter]),
            self::API.'/api/v4/contacts/*' => Http::response(['data' => ['id' => 'client-9', 'custom_field_values' => []]]),
        ]);
    }

    private function clioBill(string $billId = '123'): array
    {
        $pmsClientId = (string) Str::uuid();

        ClioConnection::query()->create([
            'provider' => 'clio',
            'pms_client_id' => $pmsClientId,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $invoice = Invoice::query()->forceCreate([
            'pms_source'          => 'clio',
            'pms_client_id'       => $pmsClientId,
            'external_invoice_id' => $billId,
            'external_client_id'  => 'client-9',
            'amount_cents'        => 50000,
            'currency'            => 'USD',
            'fund_type'           => 'OPERATING',
            'status'              => 'AWAITING_PAYMENT',
            'recipient_emails'    => ['pat@example.com'],
        ]);

        $session = PaymentSession::query()->forceCreate([
            'invoice_id'       => $invoice->id,
            'hosted_url_token' => Str::random(40),
            'status'           => 'AWAITING_PAYMENT',
            'fund_type'        => 'OPERATING',
            'idempotency_key'  => 'idem-'.Str::random(8),
            'link_status'      => 'active',
            'expires_at'       => now()->addDays(30),
        ]);

        return [$invoice, $session];
    }

    private function fakeBill(array $bill): void
    {
        Http::fake([self::API.'/api/v4/bills/*' => Http::response(['data' => ['id' => 123] + $bill])]);
    }
}
