<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Services\ClioWebhookService;
use Modules\Inbound\Services\JobTreadWebhookService;
use Modules\Inbound\Services\LawcusWebhookService;
use Modules\Inbound\Services\QuickBooksWebhookService;
use Modules\Inbound\Services\WaveWebhookService;
use Modules\Inbound\Services\ZohoWebhookService;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $source,
        ClioWebhookService $clio,
        ZohoWebhookService $zoho,
        QuickBooksWebhookService $quickbooks,
        LawcusWebhookService $lawcus,
        WaveWebhookService $wave,
        JobTreadWebhookService $jobtread,
    ): Response {
        return match ($source) {
            'clio'       => $clio->handleIncoming($request),
            'zoho'       => $zoho->handleIncoming($request),
            'quickbooks' => $quickbooks->handleIncoming($request),
            'lawcus'     => $lawcus->handleIncoming($request),
            'wave'       => $wave->handleIncoming($request),
            'jobtread'   => $jobtread->handleIncoming($request),
            default      => response()->json(['accepted' => true, 'source' => $source], 202),
        };
    }
}
