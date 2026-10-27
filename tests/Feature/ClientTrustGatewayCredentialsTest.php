<?php

namespace Tests\Feature;

use Modules\Inbound\Models\Client;
use Tests\TestCase;

class ClientTrustGatewayCredentialsTest extends TestCase
{
    private function client(array $trust = []): Client
    {
        return (new Client)->forceFill([
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

    public function test_invoices_use_the_operating_mid(): void
    {
        $creds = $this->client(['fluidpay' => ['mid_identifier' => 'MID-IOLTA', 'processor_id' => 'proc-iolta']])
            ->gatewayCredentialsFor('FluidPay', 'OPERATING');

        $this->assertSame('MID-OPERATING', $creds['mid_identifier']);
        $this->assertSame('proc-operating', $creds['processor_id']);
        $this->assertSame('api_operating', $creds['api_key']);
        $this->assertSame('production', $creds['environment']);
    }

    public function test_trust_in_the_same_account_uses_the_iolta_mid_with_the_operating_keys(): void
    {
        $creds = $this->client(['fluidpay' => ['mid_identifier' => 'MID-IOLTA', 'processor_id' => 'proc-iolta']])
            ->gatewayCredentialsFor('fluidpay', 'TRUST');

        $this->assertSame('MID-IOLTA', $creds['mid_identifier']);
        $this->assertSame('proc-iolta', $creds['processor_id']);
        $this->assertSame('api_operating', $creds['api_key']);
    }

    public function test_trust_in_a_separate_account_uses_the_iolta_keys(): void
    {
        $creds = $this->client(['fluidpay' => [
            'api_key' => 'api_iolta',
            'public_key' => 'pub_iolta',
            'mid_identifier' => 'MID-IOLTA',
        ]])->gatewayCredentialsFor('fluidpay', 'TRUST');

        $this->assertSame('api_iolta', $creds['api_key']);
        $this->assertSame('pub_iolta', $creds['public_key']);
        $this->assertSame('production', $creds['environment']);
    }

    public function test_an_iolta_mid_needs_its_own_keys_or_a_processor_id(): void
    {
        $this->assertFalse($this->client()->hasIoltaMid('fluidpay'));
        $this->assertFalse($this->client(['fluidpay' => ['mid_identifier' => 'MID-IOLTA']])->hasIoltaMid('fluidpay'));
        $this->assertTrue($this->client(['fluidpay' => ['processor_id' => 'proc-iolta']])->hasIoltaMid('FluidPay'));
        $this->assertTrue($this->client(['fluidpay' => ['api_key' => 'api_iolta']])->hasIoltaMid('fluidpay'));
        $this->assertFalse($this->client(['fluidpay' => ['processor_id' => 'proc-iolta']])->hasIoltaMid('paya'));
    }

    public function test_only_lawcus_clients_require_an_iolta_mid(): void
    {
        $this->assertTrue((new Client)->forceFill(['client_pms' => 'LAWCUS'])->requiresIoltaMid());
        $this->assertFalse((new Client)->forceFill(['client_pms' => 'QUICKBOOKS'])->requiresIoltaMid());
    }

    public function test_trust_never_inherits_the_operating_mid_or_processor(): void
    {
        $creds = $this->client()->gatewayCredentialsFor('fluidpay', 'TRUST');

        $this->assertArrayNotHasKey('mid_identifier', $creds);
        $this->assertArrayNotHasKey('processor_id', $creds);
        $this->assertSame('api_operating', $creds['api_key']);
    }
}
