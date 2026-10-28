<?php

namespace Tests\Feature;

use Modules\Inbound\Models\Client;
use Tests\TestCase;

class ClientTrustGatewayCredentialsTest extends TestCase
{
    private function client(array $trust = [], string $pms = 'LAWCUS'): Client
    {
        return (new Client)->forceFill([
            'client_pms' => $pms,
            'gateway_credentials' => [
                'environment' => 'production',
                'fluidpay' => [
                    'api_key' => 'api_operating',
                    'public_key' => 'pub_operating',
                    'mid_identifier' => 'MID-OPERATING',
                    'processor_id' => 'proc-operating',
                ],
            ],
            'trust_gateway_credentials' => $trust ?: null,
        ]);
    }

    private const IOLTA_FLUIDPAY = ['fluidpay' => [
        'api_key' => 'api_iolta',
        'public_key' => 'pub_iolta',
        'mid_identifier' => 'MID-IOLTA',
    ]];

    public function test_invoices_use_the_operating_mid(): void
    {
        $creds = $this->client(self::IOLTA_FLUIDPAY)->gatewayCredentialsFor('FluidPay', 'OPERATING');

        $this->assertSame('MID-OPERATING', $creds['mid_identifier']);
        $this->assertSame('proc-operating', $creds['processor_id']);
        $this->assertSame('api_operating', $creds['api_key']);
        $this->assertSame('production', $creds['environment']);
    }

    public function test_trust_uses_the_iolta_keys(): void
    {
        $creds = $this->client(self::IOLTA_FLUIDPAY)->gatewayCredentialsFor('fluidpay', 'TRUST');

        $this->assertSame('api_iolta', $creds['api_key']);
        $this->assertSame('pub_iolta', $creds['public_key']);
        $this->assertSame('MID-IOLTA', $creds['mid_identifier']);
        $this->assertSame('production', $creds['environment']);
    }

    public function test_trust_never_borrows_the_operating_keys(): void
    {
        $creds = $this->client(['fluidpay' => ['processor_id' => 'proc-iolta']])->gatewayCredentialsFor('fluidpay', 'TRUST');

        $this->assertSame(['processor_id' => 'proc-iolta', 'environment' => 'production'], $creds);
        $this->assertSame(['environment' => 'production'], $this->client()->gatewayCredentialsFor('fluidpay', 'TRUST'));
    }

    public function test_an_iolta_mid_needs_all_of_its_own_keys(): void
    {
        $this->assertTrue($this->client(self::IOLTA_FLUIDPAY)->hasIoltaMid('FluidPay'));

        $this->assertFalse($this->client()->hasIoltaMid('fluidpay'));
        $this->assertFalse($this->client(['fluidpay' => ['mid_identifier' => 'MID-IOLTA']])->hasIoltaMid('fluidpay'));
        $this->assertFalse($this->client(['fluidpay' => ['processor_id' => 'proc-iolta']])->hasIoltaMid('fluidpay'));
        $this->assertFalse($this->client(['fluidpay' => ['api_key' => 'api_iolta', 'public_key' => ' ']])->hasIoltaMid('fluidpay'));

        $this->assertTrue($this->client(['paya' => ['username' => 'u', 'password' => 'p', 'terminal_id' => '2001']])->hasIoltaMid('paya'));
        $this->assertFalse($this->client(['paya' => ['terminal_id' => '2001']])->hasIoltaMid('paya'));
        $this->assertFalse($this->client(self::IOLTA_FLUIDPAY)->hasIoltaMid('paya'));
    }

    public function test_only_lawcus_clients_require_an_iolta_mid(): void
    {
        $this->assertTrue((new Client)->forceFill(['client_pms' => 'LAWCUS'])->requiresIoltaMid());
        $this->assertFalse((new Client)->forceFill(['client_pms' => 'QUICKBOOKS'])->requiresIoltaMid());
    }

    public function test_other_clients_trust_money_keeps_the_operating_keys_but_not_the_operating_mid(): void
    {
        $creds = $this->client([], 'CLIO')->gatewayCredentialsFor('fluidpay', 'TRUST');

        $this->assertArrayNotHasKey('mid_identifier', $creds);
        $this->assertArrayNotHasKey('processor_id', $creds);
        $this->assertSame('api_operating', $creds['api_key']);
    }
}
