<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\PaymentSessionService;
use Modules\Inbound\Http\Controllers\ClientConfigController;
use Modules\Inbound\Models\Client;
use Tests\TestCase;

/**
 * Lawcus/Clio firms keep separate offline payment details per account (e.g. a Zelle for
 * IOLTA and one for operating): a Trust Request's payment page shows only the IOLTA
 * instructions, a bill's only the operating ones.
 */
class CashInstructionsPerAccountTest extends TestCase
{
    use RefreshDatabase;

    private const DETAILS = [
        'business_name'      => 'RR Law',
        'instructions'       => 'Zelle: operating@rrlaw.test',
        'trust_instructions' => 'Zelle: iolta@rrlaw.test',
    ];

    public function test_each_account_page_shows_only_its_own_instructions(): void
    {
        $client = $this->client('LAWCUS', self::DETAILS);

        $trust     = $this->cashDetailsOnPage($client, 'TRUST');
        $operating = $this->cashDetailsOnPage($client, 'OPERATING');

        $this->assertSame('Zelle: iolta@rrlaw.test', $trust['instructions']);
        $this->assertSame('Zelle: operating@rrlaw.test', $operating['instructions']);
        $this->assertArrayNotHasKey('trust_instructions', $trust);
        $this->assertArrayNotHasKey('trust_instructions', $operating);
        $this->assertSame('RR Law', $trust['business_name']);
    }

    public function test_a_blank_iolta_field_shows_no_instructions_on_trust_pages(): void
    {
        $client = $this->client('CLIO', ['business_name' => 'RR Law', 'instructions' => 'Zelle: operating@rrlaw.test']);

        $this->assertArrayNotHasKey('instructions', $this->cashDetailsOnPage($client, 'TRUST'));
    }

    public function test_each_account_page_shows_only_its_own_zelle_id(): void
    {
        $client = $this->client('CLIO', ['zelle_id' => 'operating@rrlaw.test', 'trust_zelle_id' => 'iolta@rrlaw.test']);

        $trust     = $this->cashDetailsOnPage($client, 'TRUST');
        $operating = $this->cashDetailsOnPage($client, 'OPERATING');

        $this->assertSame('iolta@rrlaw.test', $trust['zelle_id']);
        $this->assertSame('operating@rrlaw.test', $operating['zelle_id']);
        $this->assertArrayNotHasKey('trust_zelle_id', $trust);
        $this->assertArrayNotHasKey('trust_zelle_id', $operating);
    }

    public function test_a_blank_zelle_id_shows_nothing_and_never_falls_back_to_the_other_account(): void
    {
        $operatingOnly = $this->client('CLIO', ['zelle_id' => 'operating@rrlaw.test']);
        $this->assertArrayNotHasKey('zelle_id', $this->cashDetailsOnPage($operatingOnly, 'TRUST'), 'wrong bank account');

        $none = $this->client('CLIO', ['business_name' => 'RR Law']);
        $this->assertArrayNotHasKey('zelle_id', $this->cashDetailsOnPage($none, 'OPERATING'));
    }

    public function test_zelle_ids_are_saved_per_account_and_blanks_are_dropped(): void
    {
        $clio = $this->client('CLIO', []);
        $this->saveFees($clio, extra: ['cd_zelle_id' => ' operating@rrlaw.test ', 'cd_trust_zelle_id' => 'iolta@rrlaw.test']);
        $saved = $clio->fresh()->cash_discount_details;
        $this->assertSame('operating@rrlaw.test', $saved['zelle_id']);
        $this->assertSame('iolta@rrlaw.test', $saved['trust_zelle_id']);

        $this->saveFees($clio, extra: ['cd_zelle_id' => '', 'cd_trust_zelle_id' => '   ']);
        $saved = $clio->fresh()->cash_discount_details;
        $this->assertArrayNotHasKey('zelle_id', $saved);
        $this->assertArrayNotHasKey('trust_zelle_id', $saved);

        $quickBooks = $this->client('QUICKBOOKS', []);
        $this->saveFees($quickBooks, extra: ['cd_zelle_id' => 'pay@acme.test', 'cd_trust_zelle_id' => 'iolta@acme.test']);
        $this->assertSame('pay@acme.test', $quickBooks->fresh()->cash_discount_details['zelle_id']);
        $this->assertArrayNotHasKey('trust_zelle_id', $quickBooks->fresh()->cash_discount_details);
    }

    public function test_the_iolta_field_is_saved_for_law_firms_only(): void
    {
        $lawcus = $this->client('LAWCUS', []);
        $this->saveFees($lawcus);
        $this->assertSame(self::DETAILS['trust_instructions'], $lawcus->fresh()->cash_discount_details['trust_instructions']);

        $quickBooks = $this->client('QUICKBOOKS', []);
        $this->saveFees($quickBooks);
        $this->assertArrayNotHasKey('trust_instructions', $quickBooks->fresh()->cash_discount_details);
    }

    public function test_twenty_lines_of_wire_instructions_save_for_both_accounts(): void
    {
        // ~20 lines / ~1,500 characters, typed in a browser (\r\n line breaks).
        $wire = implode("\r\n", array_map(fn ($i) => str_pad("Line {$i}: Bank / routing / account details", 76, '.'), range(1, 20)));
        $this->assertGreaterThan(1500, strlen($wire));

        $clio = $this->client('CLIO', []);
        $this->saveFees($clio, $wire, $wire);

        $saved = $clio->fresh()->cash_discount_details;
        $this->assertSame(str_replace("\r\n", "\n", $wire), $saved['instructions']);
        $this->assertSame(str_replace("\r\n", "\n", $wire), $saved['trust_instructions']);
        $this->assertSame(20, substr_count($saved['instructions'], "\n") + 1, 'line breaks are kept');
    }

    public function test_instructions_over_the_limit_are_rejected_not_silently_dropped(): void
    {
        $clio = $this->client('CLIO', ['instructions' => 'Zelle: operating@rrlaw.test']);

        try {
            $this->saveFees($clio, str_repeat('x', ClientConfigController::CASH_INSTRUCTIONS_MAX + 1));
            $this->fail('expected a validation error');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('cd_instructions', $e->errors());
        }

        $this->assertSame('Zelle: operating@rrlaw.test', $clio->fresh()->cash_discount_details['instructions']);
    }

    public function test_the_migration_keeps_todays_instructions_on_trust_pages(): void
    {
        $lawcus     = $this->client('LAWCUS', ['instructions' => 'Zelle: firm@rrlaw.test']);
        $quickBooks = $this->client('QUICKBOOKS', ['instructions' => 'Zelle: firm@acme.test']);

        (require base_path('database/migrations/2026_11_13_000002_copy_cash_instructions_to_iolta_tab.php'))->up();

        $this->assertSame('Zelle: firm@rrlaw.test', $lawcus->fresh()->cash_discount_details['trust_instructions']);
        $this->assertArrayNotHasKey('trust_instructions', $quickBooks->fresh()->cash_discount_details);
    }

    private function cashDetailsOnPage(Client $client, string $fundType): array
    {
        $invoice = Invoice::query()->forceCreate([
            'pms_source'          => strtolower($client->client_pms),
            'pms_client_id'       => $client->pms_client_id,
            'external_invoice_id' => (string) Str::uuid(),
            'external_client_id'  => 'customer-1',
            'invoice_number'      => '1001',
            'status'              => 'SENT',
            'fund_type'           => $fundType,
            'amount_cents'        => 10000,
            'currency'            => 'USD',
        ]);
        $session = app(PaymentSessionService::class)->create($invoice);

        return (array) $this->get(route('payment.page.show', $session->hosted_url_token))
            ->assertOk()
            ->viewData('feeConfig')['cash_discount_details'];
    }

    private function saveFees(Client $client, ?string $instructions = null, ?string $trustInstructions = null, array $extra = []): void
    {
        app(ClientConfigController::class)->updateFees(Request::create('/', 'POST', $extra + [
            'fee_surcharge_enabled' => '1',
            'fee_mode'              => 'cash_discount',
            'cc_fee_percent'        => '3',
            'ach_fee_percent'       => '1',
            'fee_disclosure'        => 'A fee applies to card payments.',
            'cd_business_name'      => 'RR Law',
            'cd_address'            => '1 Main St',
            'cd_city'               => 'Chicago',
            'cd_state'              => 'IL',
            'cd_zip'                => '60601',
            'cd_phone'              => '312-555-1234',
            'cd_email'              => 'billing@rrlaw.test',
            'cd_instructions'       => $instructions ?? self::DETAILS['instructions'],
            'cd_trust_instructions' => $trustInstructions ?? self::DETAILS['trust_instructions'],
        ]), $client->pms_client_id);
    }

    private function client(string $pms, array $details): Client
    {
        return Client::query()->forceCreate([
            'id'                    => (string) Str::uuid(),
            'pms_client_id'         => (string) Str::uuid(),
            'client_name'           => 'RR Law',
            'client_pms'            => $pms,
            'fee_mode'              => 'cash_discount',
            'cash_discount_details' => $details,
        ]);
    }
}
