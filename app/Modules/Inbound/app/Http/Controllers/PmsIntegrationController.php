<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Services\PmsConnectorRegistry;

class PmsIntegrationController extends Controller
{
    public function show(string $provider, PmsConnectorRegistry $registry): View
    {
        $connector = $registry->for($provider);
        $pmsClientId = (string) request()->query('pms_client_id', '');
        $client = $pmsClientId !== ''
            ? Client::query()->where('pms_client_id', $pmsClientId)->first()
            : null;
        $connection = $connector->connection($pmsClientId);
        $data = $connector->integrationData($client, $connection);

        return view('inbound::pms-integration', [
            'provider' => $provider,
            'providerLabel' => $connector->label(),
            'client' => $client,
            'clients' => Client::query()->where('client_pms', strtoupper($provider))->latest('created_at')->get(),
            'connection' => $connection,
            'success' => request()->query('success'),
            'error' => request()->query('error'),
            'connectUrl' => $client
                ? route("inbound.{$provider}.connect", ['pms_client_id' => $client->pms_client_id])
                : null,
            'callbackUrl' => route("inbound.{$provider}.callback"),
            ...$data,
        ]);
    }
}
