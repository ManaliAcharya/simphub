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
    public function handle(Request $request, string $pmsSource): JsonResponse
    {
        $adapter = $this->adapterFactory->make($pmsSource);

        if (! $adapter->verifyWebhookSignature($request)) {
            AuditLogger::log('WEBHOOK_SIGNATURE_FAILED', 'system', $pmsSource);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        IngestInvoiceJob::dispatch($pmsSource, $request->all());
        return response()->json(['status' => 'queued'], 202);
    }

}
