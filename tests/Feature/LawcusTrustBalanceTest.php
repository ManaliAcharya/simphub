<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\PaymentSessionService;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\LawcusConnection;
use Modules\Inbound\Services\LawcusTrustBalance;
use Modules\Payment\Mail\PaymentLinkMail;
use Tests\TestCase;

/**
 * "Available trust balance" on Lawcus invoice emails and PDFs: the trust balance of the
 * invoice's matter, as Lawcus prints it with "Show Trust Balance" on.
 */
class LawcusTrustBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private array $accounts = [];

    private bool $lawcusDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.lawcus.rest_api_base_url', 'https://api.us.lawcus.com');

        $this->client = Client::query()->forceCreate([
            'id' => (string) Str::uuid(), 'pms_client_id' => (string) Str::uuid(), 'client_name' => 'RR Law', 'client_pms' => 'LAWCUS',
        ]);

        LawcusConnection::query()->create(['provider' => 'lawcus', 'pms_client_id' => $this->client->pms_client_id, 'access_token' => 'api-token']);

        Http::fake(['https://api.us.lawcus.com/reports/client-trust*' => fn () => $this->lawcusDown
            ? Http::response(['message' => 'error'], 500)
            : Http::response([['key' => 9, 'accounts' => $this->accounts]])]);
    }

    public function test_the_matters_trust_balance_is_shown(): void
    {
        $this->accounts = [
            ['name' => 'Trust Account', 'matter_name' => 'Smith v. Jones', 'matter_display_number' => '01-Smith', 'total' => 1555],
            ['name' => 'Trust Account', 'matter_name' => 'Other matter', 'matter_display_number' => '02-Other', 'total' => 400],
        ];

        $this->assertSame(155500, app(LawcusTrustBalance::class)->forInvoice($this->invoice()));
        $this->assertSame('1,555.00', app(LawcusTrustBalance::class)->formatted($this->invoice()));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'client_id=9') && str_contains($request->url(), 'display_zero=false'));
    }

    public function test_trust_held_for_the_client_but_not_on_the_matter_is_not_shown(): void
    {
        // Lawcus' own invoice PDF leaves this out too.
        $this->accounts = [['name' => 'Trust Account', 'matter_name' => null, 'matter_display_number' => null, 'total' => 205]];

        $this->assertNull(app(LawcusTrustBalance::class)->forInvoice($this->invoice()));
    }

    public function test_nothing_is_shown_for_zero_without_the_toggle_or_when_lawcus_fails(): void
    {
        $this->accounts = [['name' => 'Trust Account', 'matter_name' => 'Smith v. Jones', 'matter_display_number' => '01-Smith', 'total' => 0]];
        $this->assertNull(app(LawcusTrustBalance::class)->forInvoice($this->invoice()));

        $this->accounts = [['name' => 'Trust Account', 'matter_name' => 'Smith v. Jones', 'matter_display_number' => '01-Smith', 'total' => 1555]];
        $this->assertNull(app(LawcusTrustBalance::class)->forInvoice($this->invoice(['show_trust_balance' => false])));

        $this->lawcusDown = true;
        $this->assertNull(app(LawcusTrustBalance::class)->forInvoice($this->invoice()));
    }

    public function test_the_invoice_email_shows_the_balance(): void
    {
        $this->accounts = [['name' => 'Trust Account', 'matter_name' => 'Smith v. Jones', 'matter_display_number' => '01-Smith', 'total' => 1555]];
        $invoice = $this->invoice();
        $session = app(PaymentSessionService::class)->create($invoice);

        (new PaymentLinkMail($invoice, $session, 'https://pay.test/x'))
            ->assertSeeInHtml('Available Trust Balance')
            ->assertSeeInHtml('USD 1,555.00');

        $this->accounts = [];
        (new PaymentLinkMail($invoice, $session, 'https://pay.test/x'))->assertDontSeeInHtml('Available Trust Balance');
    }

    private function invoice(array $data = []): Invoice
    {
        return Invoice::query()->forceCreate([
            'pms_source'          => 'lawcus',
            'pms_client_id'       => $this->client->pms_client_id,
            'external_invoice_id' => (string) Str::uuid(),
            'external_client_id'  => '9',
            'invoice_number'      => '00040',
            'status'              => 'SENT',
            'fund_type'           => 'OPERATING',
            'amount_cents'        => 25000,
            'currency'            => 'USD',
            'recipient_emails'    => ['client@example.com'],
            'raw_payload'         => ['invoice' => ['data' => $data + [
                'client_id'             => 9,
                'show_trust_balance'    => true,
                'matter_name'           => 'Smith v. Jones',
                'matter_display_number' => '01-Smith',
            ]]],
        ]);
    }
}
