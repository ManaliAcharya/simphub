<?php

namespace Tests\Feature;

use Modules\Inbound\Models\Client;
use Modules\Inbound\Services\FeeAccountingGuard;
use Tests\TestCase;

class FeeAccountingGuardTest extends TestCase
{
    private function client(string $pms, array $attributes = []): Client
    {
        return (new Client)->forceFill(['client_pms' => $pms] + $attributes);
    }

    private function missing(Client $client, array $overrides = []): array
    {
        return (new FeeAccountingGuard)->missingSettings($client, $overrides);
    }

    public function test_quickbooks_fee_without_surcharge_setup_lists_everything_missing(): void
    {
        $client = $this->client('QUICKBOOKS', ['fee_surcharge_enabled' => true, 'cc_fee_percent' => '3.5']);

        $this->assertSame(
            ['the surcharge split turned on', 'a surcharge income account', 'a deposit account'],
            $this->missing($client),
        );
    }

    public function test_quickbooks_fee_with_full_surcharge_setup_is_allowed(): void
    {
        $client = $this->client('QUICKBOOKS', [
            'fee_surcharge_enabled'   => true,
            'cc_fee_percent'          => '3.5',
            'qb_surcharge_enabled'    => true,
            'qb_surcharge_account_id' => '88',
            'qb_default_account_id'   => '139',
        ]);

        $this->assertSame([], $this->missing($client));
    }

    public function test_no_fee_means_nothing_is_required(): void
    {
        $this->assertSame([], $this->missing($this->client('QUICKBOOKS', ['fee_surcharge_enabled' => false, 'cc_fee_percent' => '3.5'])));
        $this->assertSame([], $this->missing($this->client('QUICKBOOKS', ['fee_surcharge_enabled' => true, 'cc_fee_percent' => '0'])));
    }

    public function test_unsaved_values_are_checked_instead_of_saved_ones(): void
    {
        $client = $this->client('QUICKBOOKS', ['qb_surcharge_enabled' => true, 'qb_default_account_id' => '139']);

        $this->assertSame(
            ['a surcharge income account'],
            $this->missing($client, ['fee_surcharge_enabled' => true, 'ach_fee_percent' => 1.0]),
        );
    }

    public function test_wave_uses_its_own_settings(): void
    {
        $client = $this->client('WAVE', [
            'fee_surcharge_enabled'   => true,
            'cc_fee_percent'          => '2.99',
            'wave_surcharge_enabled'  => true,
            'wave_default_account_id' => 'acct',
        ]);

        $this->assertSame(['a surcharge income account'], $this->missing($client));
    }

    public function test_pms_without_fee_accounts_has_nothing_to_require(): void
    {
        foreach (['ZOHO', 'CLIO', 'LAWCUS', 'ADVANCEDMD', 'CUSTOM'] as $pms) {
            $client = $this->client($pms, ['fee_surcharge_enabled' => true, 'cc_fee_percent' => '3.5']);

            $this->assertSame([], $this->missing($client), $pms);
        }
    }

    public function test_quickbooks_multi_mid_fees_on_rate_counts_as_a_fee(): void
    {
        $client = $this->client('QUICKBOOKS', ['qb_multi_mid_enabled' => true]);

        $this->assertCount(3, $this->missing($client, ['has_mid_route_fee' => true]));
        $this->assertSame([], $this->missing($client, ['has_mid_route_fee' => false]));
        $this->assertSame([], $this->missing($client, ['qb_multi_mid_enabled' => false, 'has_mid_route_fee' => true]));
    }

    public function test_message_names_the_pms_and_what_is_missing(): void
    {
        $client = $this->client('QUICKBOOKS');

        $this->assertSame(
            "Fees can't be charged until the QuickBooks integration has a surcharge income account and a deposit account. "
            ."Without them the fee is booked to the customer's balance instead of fee income.",
            (new FeeAccountingGuard)->message($client, ['a surcharge income account', 'a deposit account']),
        );
    }
}
