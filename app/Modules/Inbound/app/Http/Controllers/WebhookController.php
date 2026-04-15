<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Services\ClioWebhookService;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function __invoke(Request $request, string $source, ClioWebhookService $clio): Response
    {
        if ($source === 'clio') {
            return $clio->handleIncoming($request);
        }

        return response()->json(['accepted' => true, 'source' => $source], 202);
    }
}
