<?php

namespace Tests\Feature;

use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\Client;
use Modules\Payment\Services\QbFeeOverride;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Clio Per-Invoice Fee Override: the field is read from the bill's matter, then the contact. */
class ClioFeeOverrideTest extends TestCase
{
    public static function fieldValues(): array
    {
        // [matter field, contact field, passes fee?]
        return [
            'text Yes' => [['field_name' => 'Pass Fees', 'value' => 'Yes'], null, true],
            'text no (any case)' => [['field_name' => 'pass fees', 'value' => ' no '], null, false],
            'checkbox checked' => [['field_name' => 'Pass Fees', 'field_type' => 'checkbox', 'value' => true], null, true],
            'checkbox unchecked' => [['field_name' => 'Pass Fees', 'field_type' => 'checkbox', 'value' => false], null, false],
            'checkbox as text' => [['field_name' => 'Pass Fees', 'field_type' => 'checkbox', 'value' => 'true'], null, true],
            'picklist Yes' => [['field_name' => 'Pass Fees', 'field_type' => 'picklist', 'value' => 9001, 'picklist_option' => ['id' => 9001, 'option' => 'Yes']], null, true],
            'picklist without option text' => [['field_name' => 'Pass Fees', 'field_type' => 'picklist', 'value' => 9001], null, false],
            'blank matter, contact Yes' => [null, ['field_name' => 'Pass Fees', 'value' => 'Yes'], true],
            'matter wins over contact' => [['field_name' => 'Pass Fees', 'value' => 'No'], ['field_name' => 'Pass Fees', 'value' => 'Yes'], false],
        ];
    }

    #[DataProvider('fieldValues')]
    public function test_reads_the_field_from_the_matter_then_the_contact(?array $matterField, ?array $contactField, bool $passes): void
    {
        $client = (new Client)->forceFill([
            'qb_fee_override_enabled' => true,
            'qb_fee_override_field' => 'Pass Fees',
            'qb_fee_override_blank_default' => QbFeeOverride::BLANK_ABSORB,
        ]);

        $this->assertSame($passes, QbFeeOverride::passesFee($client, $this->clioInvoice('OPERATING', $matterField, $contactField)));
    }

    public function test_only_clio_trust_requests_route_to_iolta_mids(): void
    {
        $this->assertSame('TRUST', QbFeeOverride::midRouteFundType($this->clioInvoice('TRUST', null)));
        $this->assertSame('OPERATING', QbFeeOverride::midRouteFundType($this->clioInvoice('OPERATING', null)));
        $this->assertSame('OPERATING', QbFeeOverride::midRouteFundType((new Invoice)->forceFill(['pms_source' => 'quickbooks', 'fund_type' => 'TRUST'])));
    }

    public function test_clio_bills_and_trust_requests_use_the_override(): void
    {
        $this->assertTrue(QbFeeOverride::supports($this->clioInvoice('TRUST', null)));
        $this->assertTrue(QbFeeOverride::supports($this->clioInvoice('OPERATING', null)));
        $this->assertTrue(QbFeeOverride::supports((new Invoice)->forceFill(['pms_source' => 'quickbooks', 'fund_type' => 'OPERATING'])));
        $this->assertFalse(QbFeeOverride::supports((new Invoice)->forceFill(['pms_source' => 'zoho', 'fund_type' => 'OPERATING'])));
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
}
