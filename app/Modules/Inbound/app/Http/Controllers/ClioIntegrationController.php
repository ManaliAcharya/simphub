<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClioConnection;

class ClioIntegrationController extends Controller
{
    public function show(): View
    {
        $pmsClientId = (string) request()->query('pms_client_id', '');
        $client = $pmsClientId !== ''
            ? Client::query()->where('pms_client_id', $pmsClientId)->first()
            : null;
        $connection = $pmsClientId !== ''
            ? ClioConnection::query()->where('pms_client_id', $pmsClientId)->latest('created_at')->first()
            : null;

        return view('inbound::clio-integration', [
            'client' => $client,
            'clients' => Client::query()->latest('created_at')->get(),
            'connection' => $connection,
            'success' => request()->query('success'),
            'error' => request()->query('error'),
            'connectUrl' => $client
                ? route('inbound.clio.connect', ['pms_client_id' => $client->pms_client_id])
                : null,
            'callbackUrl' => route('inbound.clio.callback'),
            'webhookUrl' => env('CLIO_WEBHOOK_CALLBACK_URL'),
        ]);
    }
}
