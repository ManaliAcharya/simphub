<?php

namespace Tests\Feature;

use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\Client;
use Modules\Payment\Services\QbFeeOverride;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LawcusFeeOverrideTest extends TestCase
{
    public static function matterFieldValues(): array
    {
        // [matter custom field value, passes fee?] — blank default is absorb
        return [
            'text Yes'          => ['Yes', true],
            'text no'           => ['no', false],
            'checkbox checked'  => [true, true],
            'checkbox cleared'  => [false, false],
            'picklist option'   => [['name' => 'Yes'], true],
            'blank'             => ['', false],
            'typo'              => ['Yse', false],
        ];
    }

    #[DataProvider('matterFieldValues')]
    public function test_reads_the_override_from_the_lawcus_matter(mixed $value, bool $passes): void
    {
        $client = (new Client)->forceFill([
            'qb_fee_override_enabled' => true,
            'qb_fee_override_field' => 'Pass Fees',
            'qb_fee_override_blank_default' => QbFeeOverride::BLANK_ABSORB,
        ]);

        $invoice = (new Invoice)->forceFill([
            'pms_source' => 'lawcus',
            'raw_payload' => ['matter' => ['custom_fields' => [
                ['type' => 'TEXT', 'name' => 'Other Field', 'value' => 'Yes'],
                ['type' => 'TEXT', 'name' => 'pass fees', 'value' => $value],
            ]]],
        ]);

        $this->assertTrue(QbFeeOverride::supports($invoice));
        $this->assertSame($passes, QbFeeOverride::passesFee($client, $invoice));
    }

    public function test_only_lawcus_trust_requests_use_trust_routes(): void
    {
        $lawcusTrust = (new Invoice)->forceFill(['pms_source' => 'lawcus', 'fund_type' => 'TRUST']);
        $lawcusOperating = (new Invoice)->forceFill(['pms_source' => 'lawcus', 'fund_type' => 'OPERATING']);
        $qbTrust = (new Invoice)->forceFill(['pms_source' => 'quickbooks', 'fund_type' => 'TRUST']);

        $this->assertSame('TRUST', QbFeeOverride::midRouteFundType($lawcusTrust));
        $this->assertSame('OPERATING', QbFeeOverride::midRouteFundType($lawcusOperating));
        $this->assertSame('OPERATING', QbFeeOverride::midRouteFundType($qbTrust));
    }
}
