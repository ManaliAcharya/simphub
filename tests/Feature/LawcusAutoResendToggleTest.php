<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Inbound\Http\Controllers\PmsIntegrationController;
use Modules\Inbound\Models\Client;
use Tests\TestCase;

/**
 * A Lawcus firm can turn "Auto-Resend Payment Link on Invoice Change" on or off itself —
 * before, the switch only showed for QuickBooks/Clio/Zoho/Wave, so a Lawcus firm that had
 * it off never got the "Updated:" email when it edited and re-saved an invoice.
 */
class LawcusAutoResendToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_lawcus_firm_can_turn_auto_resend_on(): void
    {
        $client = Client::query()->forceCreate([
            'id'                    => (string) Str::uuid(),
            'pms_client_id'         => (string) Str::uuid(),
            'client_name'           => 'Romanello & Rodriguez Law',
            'client_pms'            => 'LAWCUS',
            'auto_resend_on_change' => false,
        ]);

        $request = Request::create('/inbound/lawcus/auto-resend-toggle', 'POST', [
            'pms_client_id'         => $client->pms_client_id,
            'auto_resend_on_change' => '1',
        ]);

        app(PmsIntegrationController::class)->saveAutoResendToggle($request);

        $this->assertTrue((bool) $client->fresh()->auto_resend_on_change);
        $this->assertTrue(app('router')->has('inbound.lawcus.auto-resend-toggle'));
    }
}
