<?php

namespace Tests\Unit;

use Modules\Inbound\Models\Client;
use Tests\TestCase;

class ClientFeePercentForTest extends TestCase
{
    private function client(string $pms): Client
    {
        return new Client([
            'client_pms'            => $pms,
            'cc_fee_percent'        => '3.5',
            'ach_fee_percent'       => '1',
            'trust_cc_fee_percent'  => '0',
            'trust_ach_fee_percent' => null,
        ]);
    }

    public function test_iolta_clients_charge_trust_money_the_iolta_tab_rate(): void
    {
        foreach (['CLIO', 'lawcus'] as $pms) {
            $client = $this->client($pms);

            $this->assertSame('0.00000', $client->feePercentFor('CARD', 'TRUST'));
            $this->assertNull($client->feePercentFor('ACH', 'TRUST'));
            $this->assertSame('3.50000', $client->feePercentFor('CARD', 'OPERATING'));
            $this->assertSame('1.00000', $client->feePercentFor('ach', 'OPERATING'));
        }
    }

    public function test_other_clients_have_one_rate_for_all_money(): void
    {
        $client = $this->client('QUICKBOOKS');

        $this->assertSame('3.50000', $client->feePercentFor('CARD', 'TRUST'));
        $this->assertSame('1.00000', $client->feePercentFor('ACH', 'TRUST'));
    }
}
