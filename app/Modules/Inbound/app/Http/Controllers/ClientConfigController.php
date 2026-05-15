<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Services\ZohoRegionResolver;
use Modules\Routing\Models\RoutingRule;
use Modules\Routing\Models\TerminalConfiguration;

class ClientConfigController extends Controller
{
    public function create(ZohoRegionResolver $zohoRegions): View
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

        $availableTerminals = TerminalConfiguration::query()
            ->where('is_active', true)
            ->distinct()
            ->orderBy('terminal')
            ->pluck('terminal')
            ->filter()
            ->map(fn ($terminal) => strtoupper((string) $terminal))
            ->values()
            ->all();

        return view('inbound::clients.create', [
            'clients'            => Client::query()->latest('created_at')->get(),
            'availableGateways'  => $availableGateways,
            'availableTerminals' => $availableTerminals,
            'zohoRegions'        => $zohoRegions->options(),
        ]);
    }

    public function store(Request $request, ZohoRegionResolver $zohoRegions): RedirectResponse
    {
        $availableGateways = RoutingRule::query()
            ->where('is_active', true)
            ->distinct()
            ->pluck('gateway')
            ->filter()
            ->map(fn ($gateway) => strtoupper((string) $gateway))
            ->values()
            ->all();

        $availableTerminals = TerminalConfiguration::query()
            ->where('is_active', true)
            ->distinct()
            ->pluck('terminal')
            ->filter()
            ->map(fn ($terminal) => strtoupper((string) $terminal))
            ->values()
            ->all();

        $isTerminal = ! empty($request->input('allowed_terminals'));

        $validated = $request->validate([
            'client_name'                => ['required', 'string', 'max:255'],
            'client_pms'                 => ['required', 'string', 'max:50'],
            'zoho_region'                => ['nullable', 'string', Rule::in(array_keys($zohoRegions->options()))],
            'allowed_payment_gateways'   => ! $isTerminal && $availableGateways !== []
                ? ['required', 'array', 'min:1']
                : ['nullable', 'array'],
            'allowed_payment_gateways.*' => ['string', Rule::in($availableGateways)],
            'allowed_terminals'          => ['nullable', 'array'],
            'allowed_terminals.*'        => $availableTerminals !== []
                ? ['string', Rule::in($availableTerminals)]
                : ['string'],
            'webhook_flow_enabled'       => ['nullable', 'boolean'],
            'call_api_to_pms'            => ['nullable', 'boolean'],
            'client_calls_our_api'       => ['nullable', 'boolean'],
        ]);

        if (strtoupper($validated['client_pms']) === 'ZOHO' && empty($validated['zoho_region'])) {
            return back()
                ->withErrors(['zoho_region' => 'Zoho region is required when PMS is Zoho.'])
                ->withInput();
        }

        $client = Client::query()->create([
            'pms_client_id'            => (string) Str::uuid(),
            'setup_token'              => strtolower(Str::random(12)),
            'client_name'              => $validated['client_name'],
            'client_pms'               => strtoupper($validated['client_pms']),
            'zoho_region'              => strtoupper($validated['client_pms']) === 'ZOHO'
                ? $zohoRegions->normalize($validated['zoho_region'] ?? 'US')
                : null,
            'allowed_payment_gateways' => $isTerminal ? [] : collect($validated['allowed_payment_gateways'] ?? [])
                ->map(fn ($g) => strtoupper((string) $g))
                ->unique()->values()->all(),
            'allowed_terminals'        => $isTerminal
                ? collect($validated['allowed_terminals'])
                    ->map(fn ($t) => strtolower((string) $t))
                    ->unique()->values()->all()
                : [],
            'webhook_flow_enabled'  => ($isTerminal || strtoupper($validated['client_pms']) === 'CUSTOM') ? false : (bool) ($validated['webhook_flow_enabled'] ?? false),
            'call_api_to_pms'      => ($isTerminal || strtoupper($validated['client_pms']) === 'CUSTOM') ? false : (bool) ($validated['call_api_to_pms'] ?? false),
            'client_calls_our_api' => strtoupper($validated['client_pms']) === 'CUSTOM' ? true : ($isTerminal ? false : (bool) ($validated['client_calls_our_api'] ?? false)),
        ]);

        if ($isTerminal) {
            return redirect()->route('inbound.clients.terminal-created', [
                'pms_client_id' => $client->pms_client_id,
            ]);
        }

        if ($client->client_pms === 'CUSTOM') {
            return redirect()->route('inbound.clients.api-docs', [
                'pms_client_id' => $client->pms_client_id,
            ]);
        }

        $providerRoute = match ($client->client_pms) {
            'ZOHO'       => 'inbound.zoho.page',
            'QUICKBOOKS' => 'inbound.quickbooks.page',
            default      => 'inbound.clio.page',
        };

        return redirect()->route($providerRoute, [
            'pms_client_id' => $client->pms_client_id,
            'success' => 'Client created. Continue with PMS connection.',
        ]);
    }
}
