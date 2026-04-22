<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Modules\Inbound\Models\Client;

class ClientConfigController extends Controller
{
    public function create(): View
    {
        return view('inbound::clients.create', [
            'clients' => Client::query()->latest('created_at')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_name' => ['required', 'string', 'max:255'],
            'client_pms' => ['required', 'string', 'max:50'],
            'webhook_flow_enabled' => ['nullable', 'boolean'],
            'call_api_to_pms' => ['nullable', 'boolean'],
            'client_calls_our_api' => ['nullable', 'boolean'],
        ]);

        $client = Client::query()->create([
            'pms_client_id' => (string) Str::uuid(),
            'client_name' => $validated['client_name'],
            'client_pms' => strtoupper($validated['client_pms']),
            'webhook_flow_enabled' => (bool) ($validated['webhook_flow_enabled'] ?? false),
            'call_api_to_pms' => (bool) ($validated['call_api_to_pms'] ?? false),
            'client_calls_our_api' => (bool) ($validated['client_calls_our_api'] ?? false),
        ]);

        return redirect()->route('inbound.clio.page', [
            'pms_client_id' => $client->pms_client_id,
            'success' => 'Client created. Continue with Clio connection.',
        ]);
    }
}
