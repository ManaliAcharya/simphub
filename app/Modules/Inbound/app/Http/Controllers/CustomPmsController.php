<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Models\Client;
use Modules\Routing\Models\RoutingRule;

class CustomPmsController extends Controller
{
    public function apiDocs(Request $request): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
    {
        $client = Client::query()
            ->where('pms_client_id', $request->query('pms_client_id'))
            ->first();

        // Zoho CRM clients moved to the shared integration page; keep old links working.
        if ($client?->client_pms === 'ZOHOCRM') {
            return redirect()->route('inbound.zohocrm.page', ['pms_client_id' => $client->pms_client_id]);
        }

        if (! $client || $client->client_pms !== 'CUSTOM') {
            return redirect()->route('inbound.clients.create');
        }

        $availableGateways = RoutingRule::query()
            ->where('is_active', true)
            ->distinct()
            ->orderBy('gateway')
            ->pluck('gateway')
            ->filter()
            ->map(fn ($g) => strtoupper((string) $g))
            ->values()
            ->all();

        return view('inbound::clients.api-docs', [
            'client'            => $client,
            'baseUrl'           => rtrim(config('app.url'), '/'),
            'availableGateways' => $availableGateways,
        ]);
    }

    /** Standalone subscription API docs for Zoho CRM clients (opened from their config page). */
    public function subscriptionApiDocs(string $pmsClientId): \Illuminate\View\View
    {
        $client = Client::query()
            ->where('pms_client_id', $pmsClientId)
            ->where('client_pms', 'ZOHOCRM')
            ->firstOrFail();

        return view('inbound::clients.subscription-api-docs', [
            'client'  => $client,
            'baseUrl' => rtrim(config('app.url'), '/'),
        ]);
    }
}
