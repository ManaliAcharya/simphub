<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Inbound\Http\Controllers\ClientConfigController;
use Modules\Inbound\Models\Client;
use Tests\TestCase;

/** The MID / Processor ID pickers on Gateway Credentials (operating and IOLTA tabs). */
class GatewayCredentialsFluidpayLookupTest extends TestCase
{
    private function client(array $trust = []): Client
    {
        return (new Client)->forceFill([
            'client_pms' => 'LAWCUS',
            'gateway_credentials' => [
                'environment' => 'sandbox',
                'fluidpay' => ['api_key' => 'api_operating', 'processor_id' => 'proc-operating'],
            ],
            'trust_gateway_credentials' => $trust ?: null,
        ]);
    }

    private function controller(string $method): \Closure
    {
        $controller = app(ClientConfigController::class);

        return \Closure::bind(fn (...$args) => $this->{$method}(...$args), $controller, ClientConfigController::class);
    }

    private function credentials(array $input, Client $client): array
    {
        return $this->controller('fluidpayLookupCredentials')(Request::create('/', 'POST', $input), $client);
    }

    public function test_iolta_lookup_never_borrows_the_operating_key(): void
    {
        $creds = $this->credentials(['fund_type' => 'TRUST'], $this->client(['fluidpay' => ['processor_id' => 'proc-iolta']]));

        $this->assertSame(['environment' => 'sandbox'], $creds);
    }

    public function test_iolta_lookup_uses_the_iolta_key(): void
    {
        $creds = $this->credentials(['fund_type' => 'TRUST'], $this->client(['fluidpay' => ['api_key' => 'api_iolta']]));

        $this->assertSame('api_iolta', $creds['api_key']);
    }

    public function test_operating_lookup_uses_the_operating_key(): void
    {
        $creds = $this->credentials(['fund_type' => 'OPERATING'], $this->client(['fluidpay' => ['api_key' => 'api_iolta']]));

        $this->assertSame('api_operating', $creds['api_key']);
    }

    public function test_a_typed_key_and_unsaved_environment_win(): void
    {
        $creds = $this->credentials(
            ['fund_type' => 'TRUST', 'api_key' => ' api_typed ', 'environment' => 'production'],
            $this->client(['fluidpay' => ['api_key' => 'api_iolta']]),
        );

        $this->assertSame(['environment' => 'production', 'api_key' => 'api_typed'], $creds);
    }

    public function test_a_lookup_needs_a_fund_type_or_a_route_type(): void
    {
        $rules = $this->controller('fluidpayLookupRules')();

        $this->assertTrue(Validator::make([], $rules)->fails());
        $this->assertTrue(Validator::make(['fund_type' => 'IOLTA'], $rules)->fails());
        $this->assertFalse(Validator::make(['fund_type' => 'TRUST'], $rules)->fails());
        $this->assertFalse(Validator::make(['route_type' => 'fees_on'], $rules)->fails());
    }
}
