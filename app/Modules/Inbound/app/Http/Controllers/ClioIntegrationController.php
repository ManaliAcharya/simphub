<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Modules\Inbound\Models\ClioConnection;

class ClioIntegrationController extends Controller
{
    public function show(): View
    {
        $connection = ClioConnection::query()->first();

        return view('clio-integration', [
            'connection' => $connection,
            'success' => request()->query('success'),
            'error' => request()->query('error'),
            'connectUrl' => route('inbound.clio.connect'),
            'callbackUrl' => route('inbound.clio.callback'),
            //'webhookUrl' => route('api.inbound.webhooks.receive', ['source' => 'clio']),
            'webhookUrl' => env('CLIO_WEBHOOK_CALLBACK_URL'),
        ]);
    }
}
