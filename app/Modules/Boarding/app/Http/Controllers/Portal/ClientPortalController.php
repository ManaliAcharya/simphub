<?php

namespace Modules\Boarding\Http\Controllers\Portal;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Boarding\Models\BoardingMasterLink;

class ClientPortalController extends Controller
{
    public function show(Request $request)
    {
        $client = $request->attributes->get('client_account')->owner;
        $client->load(['merchants' => fn ($q) => $q->latest(), 'masterLinks']);
        $apiKey = $client->client_api_key;

        return view('boarding::portal.config-page', compact('client', 'apiKey'));
    }

    public function updateMasterLinks(Request $request): RedirectResponse
    {
        $client = $request->attributes->get('client_account')->owner;

        $data = $request->validate([
            'rack_rate_url' => ['nullable', 'url', 'max:2048'],
            'flat_rate_url' => ['nullable', 'url', 'max:2048'],
        ]);

        foreach (['rack_rate' => 'rack_rate_url', 'flat_rate' => 'flat_rate_url'] as $tier => $field) {
            if (empty($data[$field])) {
                continue;
            }

            BoardingMasterLink::updateOrCreate(
                ['client_id' => $client->id, 'processor' => 'square', 'tier' => $tier],
                ['url' => $data[$field]],
            );
        }

        return back()->with('success', 'Master Square links updated.');
    }
}
