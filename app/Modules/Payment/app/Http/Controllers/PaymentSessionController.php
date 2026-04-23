<?php

namespace Modules\Payment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Billing\Models\PaymentSession;
use Modules\Payment\Services\PaymentCheckoutService;
use RuntimeException;

class PaymentSessionController extends Controller
{
    public function show(string $session, PaymentCheckoutService $checkout): JsonResponse
    {
        $paymentSession = PaymentSession::query()
            ->where('hosted_url_token', $session)
            ->firstOrFail();

        try {
            return response()->json($checkout->details($paymentSession));
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function submit(Request $request, string $session, PaymentCheckoutService $checkout): JsonResponse
    {
        $paymentSession = PaymentSession::query()
            ->where('hosted_url_token', $session)
            ->firstOrFail();

        $request->validate([
            'token' => ['required', 'string'],
            'payment_method' => ['nullable', 'string'],
            'routing_rule_id' => ['required', 'string'],
        ]);

        try {
            $transaction = $checkout->submit(
                session: $paymentSession,
                token: (string) $request->string('token'),
                paymentMethod: (string) ($request->input('payment_method') ?: 'CARD'),
                routingRuleId: (string) $request->string('routing_rule_id'),
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'status' => 'APPROVED',
            'transaction_id' => $transaction->id,
            'gateway_txn_id' => $transaction->gateway_txn_id,
        ]);
    }
}
