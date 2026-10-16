<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClientMidRoute;
use Tests\TestCase;

class ClientMidRouteRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_rate_percent_keeps_five_decimal_places(): void
    {
        $client = Client::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'pms_client_id' => (string) Str::uuid(),
            'client_name' => 'RR Law',
            'client_pms' => 'LAWCUS',
        ]);

        $route = ClientMidRoute::query()->create([
            'client_id' => $client->id,
            'route_type' => 'fees_on',
            'gateway' => 'fluidpay',
            'mid_identifier' => 'MID-TEST-1',
            'rate_percent' => '3.48753',
        ]);

        $this->assertSame('3.48753', $route->fresh()->rate_percent);
        // 3.48753% of $1,000.00 = $34.8753 -> 3488 cents, which a 2-decimal rate (3.49%) would make 3490.
        $this->assertSame(3488, (int) round(100000 * (float) $route->fresh()->rate_percent / 100));
    }
}
