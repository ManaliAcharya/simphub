<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Inbound\Models\Client;
use Modules\Routing\Models\RoutingRule;

class ClientConfigController extends Controller
{
    public function create(): View
    {
        $availableGateways = RoutingRule::query()
            ->where('is_active', true)
            ->distinct()
            ->orderBy('gateway')
            ->pluck('gateway')
            ->filter()
            ->map(fn ($gateway) => strtoupper((string) $gateway))
            ->values()
            ->all();

        return view('inbound::clients.create', [
            'clients' => Client::query()->latest('created_at')->get(),
            'availableGateways' => $availableGateways,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $availableGateways = RoutingRule::query()
            ->where('is_active', true)
            ->distinct()
            ->pluck('gateway')
            ->filter()
            ->map(fn ($gateway) => strtoupper((string) $gateway))
            ->values()
            ->all();

        $validated = $request->validate([
            'client_name' => ['required', 'string', 'max:255'],
            'client_pms' => ['required', 'string', 'max:50'],
            'allowed_payment_gateways' => $availableGateways === [] ? ['nullable', 'array'] : ['required', 'array', 'min:1'],
            'allowed_payment_gateways.*' => ['string', Rule::in($availableGateways)],
            'webhook_flow_enabled' => ['nullable', 'boolean'],
            'call_api_to_pms' => ['nullable', 'boolean'],
            'client_calls_our_api' => ['nullable', 'boolean'],
        ]);

        $client = Client::query()->create([
            'pms_client_id' => (string) Str::uuid(),
            'setup_token' => strtolower(Str::random(12)),
            'client_name' => $validated['client_name'],
            'client_pms' => strtoupper($validated['client_pms']),
            'allowed_payment_gateways' => collect($validated['allowed_payment_gateways'] ?? [])
                ->map(fn ($gateway) => strtoupper((string) $gateway))
                ->unique()
                ->values()
                ->all(),
            'webhook_flow_enabled' => (bool) ($validated['webhook_flow_enabled'] ?? false),
            'call_api_to_pms' => (bool) ($validated['call_api_to_pms'] ?? false),
            'client_calls_our_api' => (bool) ($validated['client_calls_our_api'] ?? false),
        ]);

        $providerRoute = match ($client->client_pms) {
            'ZOHO' => 'inbound.zoho.page',
            default => 'inbound.clio.page',
        };

        return redirect()->route($providerRoute, [
            'pms_client_id' => $client->pms_client_id,
            'success' => 'Client created. Continue with PMS connection.',
        ]);
    }
}
