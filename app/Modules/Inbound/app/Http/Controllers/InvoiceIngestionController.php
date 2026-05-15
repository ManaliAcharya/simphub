<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Services\InboundInvoiceProcessor;
use Modules\Outbound\Services\PayaTokenizerService;
use Modules\Payment\Services\PaymentCheckoutService;
use Modules\Payment\Services\PaymentLinkService;
use Modules\Routing\DTOs\RoutingContext;
use Modules\Routing\Services\RoutingEngine;
use RuntimeException;

class InvoiceIngestionController extends Controller
{
    public function store(
        Request $request,
        string $source,
        InboundInvoiceProcessor $processor,
        PaymentLinkService $paymentLinks,
        PaymentCheckoutService $checkout,
        RoutingEngine $routing,
        PayaTokenizerService $tokenizer,
    ): JsonResponse {
        $externalInvoiceId = (string) (
            $request->input('external_invoice_id')
            ?? $request->input('invoice_id')
            ?? $request->input('bill_id')
            ?? $request->input('entity_id')
            ?? $request->input('id')
            ?? data_get($request->input('invoice'), 'id')
            ?? data_get($request->input('bill'), 'bill_id')
        );

        abort_if($externalInvoiceId === '', 422, 'Invoice id is required.');
        abort_if(
            (string) ($request->input('pms_client_id') ?? '') === '',
            422,
            'PMS client identifier is required.'
        );

        if ($source === 'custom') {
            $request->validate([
                'routing_number' => ['required', 'string', 'regex:/^\d{9}$/'],
                'account_number' => ['required', 'string', 'regex:/^\d{4,17}$/'],
                'account_type'   => ['nullable', 'string', 'in:checking,savings'],
                'amount_cents'   => ['required', 'integer', 'min:1'],
                'fund_type'      => ['required', 'string', 'in:OPERATING,TRUST'],
            ]);
        }

        try {
            $result = $processor->process($source, $externalInvoiceId, $request->all());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $invoice = $result['invoice'];
        $session = $result['payment_session'];

        // Custom PMS: run the full payment flow synchronously and return the result.
        if ($source === 'custom') {
            $token = $tokenizer->tokenize([
                'routing_number' => $request->input('routing_number'),
                'account_number' => $request->input('account_number'),
                'account_type'   => $request->input('account_type', 'checking'),
            ]);

            try {
                $decision = $routing->decide(new RoutingContext(
                    merchantId:    'default',
                    amountInCents: (int) $invoice->amount_cents,
                    paymentMethod: 'ACH',
                    fundType:      (string) $invoice->fund_type,
                    sessionId:     (string) $session->id,
                    currency:      (string) $invoice->currency,
                ));
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            try {
                $transaction = $checkout->submit(
                    session:        $session,
                    token:          $token,
                    paymentMethod:  'ACH',
                    routingRuleId:  $decision->routingRuleId,
                );
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json([
                'status'         => 'APPROVED',
                'transaction_id' => $transaction->id,
                'gateway_txn_id' => $transaction->gateway_txn_id,
                'invoice_id'     => $invoice->id,
                'invoice_status' => $invoice->fresh()->status,
            ]);
        }

        // All other PMS sources: return the payment link as before.
        return response()->json([
            'status'               => 'ok',
            'invoice_id'           => $invoice->id,
            'external_invoice_id'  => $invoice->external_invoice_id,
            'pms_client_id'        => $invoice->pms_client_id,
            'payment_session_id'   => $session->id,
            'idempotency_key'      => $session->idempotency_key,
            'payment_link_token'   => $session->hosted_url_token,
            'payment_link_url'     => $paymentLinks->urlForSession($session),
            'emails_sent'          => $result['emails_sent'] ?? 0,
        ], 202);
    }
}
