<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Services\ClioInvoiceIngestionService;

class InvoiceIngestionController extends Controller
{
    public function store(Request $request, string $source, ClioInvoiceIngestionService $clio): JsonResponse
    {
        abort_unless($source === 'clio', 404);

        $externalInvoiceId = (string) (
            $request->input('external_invoice_id')
            ?? $request->input('invoice_id')
            ?? $request->input('id')
            ?? data_get($request->input('invoice'), 'id')
        );

        abort_if($externalInvoiceId === '', 422, 'Invoice id is required.');

        $result = $clio->ingest($externalInvoiceId, $request->all());
        $invoice = $result['invoice'];
        $session = $result['payment_session'];

        return response()->json([
            'status' => 'ok',
            'invoice_id' => $invoice->id,
            'external_invoice_id' => $invoice->external_invoice_id,
            'payment_session_id' => $session->id,
            'idempotency_key' => $session->idempotency_key,
            'payment_link_token' => $session->hosted_url_token,
            'payment_link_url' => route('payment.page.show', ['session' => $session->hosted_url_token]),
            'emails_sent' => $result['emails_sent'] ?? 0,
        ], 202);
    }
}
