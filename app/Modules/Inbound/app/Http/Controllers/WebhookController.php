<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Services\ClioWebhookService;
use Modules\Inbound\Services\ZohoWebhookService;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $source,
        ClioWebhookService $clio,
        ZohoWebhookService $zoho
    ): Response
    {
        return match ($source) {
            'clio' => $clio->handleIncoming($request),
            'zoho' => $zoho->handleIncoming($request),
            default => response()->json(['accepted' => true, 'source' => $source], 202),
        };
    }
}
