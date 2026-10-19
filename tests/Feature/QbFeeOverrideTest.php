<?php

namespace Tests\Feature;

use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\Client;
use Modules\Payment\Services\QbFeeOverride;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QbFeeOverrideTest extends TestCase
{
    public static function decisions(): array
    {
        // [invoice field value, customer field value, blank default, passes fee?]
        return [
            'Yes, default absorb' => ['Yes', null, 'absorb', true],
            'yes (any case), default absorb' => [' yes ', null, 'absorb', true],
            'No, default pass' => ['No', null, 'pass', false],
            'blank, default absorb' => [null, null, 'absorb', false],
            'blank, default pass' => [null, null, 'pass', true],
            'whitespace only, default pass' => ['   ', null, 'pass', true],
            'typo, default pass' => ['Ys', null, 'pass', false],
            'typo, default absorb' => ['N/A', null, 'absorb', false],
            'blank invoice, customer No, default pass' => [null, 'No', 'pass', false],
            'blank invoice, customer Yes, default absorb' => [null, 'Yes', 'absorb', true],
            'invoice wins over customer' => ['Yes', 'No', 'absorb', true],
        ];
    }

    #[DataProvider('decisions')]
    public function test_decides_whether_the_fee_is_passed(?string $invoiceValue, ?string $customerValue, string $blankDefault, bool $passes): void
    {
        $client = (new Client)->forceFill([
            'qb_fee_override_enabled' => true,
            'qb_fee_override_field' => 'Cash Discount',
            'qb_fee_override_blank_default' => $blankDefault,
        ]);

        $invoice = (new Invoice)->forceFill(['raw_payload' => [
            'invoice' => ['Invoice' => ['CustomField' => $invoiceValue === null ? [] : [
                ['Name' => 'Cash Discount', 'StringValue' => $invoiceValue],
            ]]],
            'customer' => ['Customer' => ['CustomField' => $customerValue === null ? [] : [
                ['Name' => 'cash discount', 'StringValue' => $customerValue],
            ]]],
        ]]);

        $this->assertSame($passes, QbFeeOverride::passesFee($client, $invoice));
    }

    public function test_existing_clients_without_the_setting_keep_absorbing_on_blank(): void
    {
        $client = (new Client)->forceFill(['qb_fee_override_enabled' => true]);
        $invoice = (new Invoice)->forceFill(['raw_payload' => []]);

        $this->assertFalse(QbFeeOverride::passesFee($client, $invoice));
    }
}
