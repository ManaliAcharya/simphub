<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Services\InboundInvoiceProcessor;
use Modules\Payment\Services\PaymentLinkService;
use RuntimeException;

class InvoiceIngestionController extends Controller
{
    public function store(
        Request $request,
        string $source,
        InboundInvoiceProcessor $processor,
        PaymentLinkService $paymentLinks
    ): JsonResponse
    {
        $externalInvoiceId = (string) (
            $request->input('external_invoice_id')
            ?? $request->input('invoice_id')
            ?? $request->input('id')
            ?? data_get($request->input('invoice'), 'id')
        );

        abort_if($externalInvoiceId === '', 422, 'Invoice id is required.');
        abort_if(
            (string) ($request->input('pms_client_id') ?? '') === '',
            422,
            'PMS client identifier is required.'
        );

        try {
            $result = $processor->process($source, $externalInvoiceId, $request->all());
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $invoice = $result['invoice'];
        $session = $result['payment_session'];

        return response()->json([
            'status' => 'ok',
            'invoice_id' => $invoice->id,
            'external_invoice_id' => $invoice->external_invoice_id,
            'pms_client_id' => $invoice->pms_client_id,
            'payment_session_id' => $session->id,
            'idempotency_key' => $session->idempotency_key,
            'payment_link_token' => $session->hosted_url_token,
            'payment_link_url' => $paymentLinks->urlForSession($session),
            'emails_sent' => $result['emails_sent'] ?? 0,
        ], 202);
    }
}
