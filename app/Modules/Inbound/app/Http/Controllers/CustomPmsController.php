<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Models\Client;

class CustomPmsController extends Controller
{
    public function apiDocs(Request $request): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
    {
        $client = Client::query()
            ->where('pms_client_id', $request->query('pms_client_id'))
            ->first();

        if (! $client || $client->client_pms !== 'CUSTOM') {
            return redirect()->route('inbound.clients.create');
        }

        return view('inbound::clients.api-docs', [
            'client'  => $client,
            'baseUrl' => rtrim(config('app.url'), '/'),
        ]);
    }
}
