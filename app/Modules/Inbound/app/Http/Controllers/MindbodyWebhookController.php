<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Services\MindbodyWebhookService;
use Symfony\Component\HttpFoundation\Response;

class MindbodyWebhookController extends Controller
{
    public function __invoke(Request $request, string $siteId, MindbodyWebhookService $service): Response
    {
        return $service->handleIncoming($request, $siteId);
    }
}
