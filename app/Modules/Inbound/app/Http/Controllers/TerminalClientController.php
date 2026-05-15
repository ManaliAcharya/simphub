<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Models\Client;

class TerminalClientController extends Controller
{
    public function created(Request $request): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
    {
        $client = Client::query()
            ->where('pms_client_id', $request->query('pms_client_id'))
            ->first();

        if (! $client || ! $client->usesTerminal()) {
            return redirect()->route('inbound.clients.create');
        }

        $apiUrl = url("/api/v1/terminal/{$client->pms_client_id}/charge");

        return view('inbound::clients.terminal-created', [
            'client' => $client,
            'apiUrl' => $apiUrl,
        ]);
    }
}
