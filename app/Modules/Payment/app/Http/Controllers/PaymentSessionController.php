<?php

namespace Modules\Payment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Billing\Models\PaymentSession;
use Modules\Outbound\Services\PayaTokenizerService;
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

    public function tokenize(Request $request, string $session, PayaTokenizerService $tokenizer): JsonResponse
    {
        $paymentSession = PaymentSession::query()
            ->where('hosted_url_token', $session)
            ->firstOrFail();

        if (! in_array($paymentSession->status, ['PENDING', 'AWAITING_PAYMENT'], true)) {
            return response()->json(['message' => 'Payment session is not in a payable state.'], 422);
        }

        $validated = $request->validate([
            'routing_number' => ['required', 'string', 'regex:/^\d{9}$/'],
            'account_number' => ['required', 'string', 'regex:/^\d{4,17}$/'],
            'account_type'   => ['nullable', 'string', 'in:checking,savings'],
            'first_name'     => ['nullable', 'string', 'max:100'],
            'last_name'      => ['nullable', 'string', 'max:100'],
            'address1'       => ['nullable', 'string', 'max:255'],
            'city'           => ['nullable', 'string', 'max:100'],
            'state'          => ['nullable', 'string', 'size:2'],
            'zip'            => ['nullable', 'string', 'max:10'],
            'phone_number'   => ['nullable', 'string', 'max:20'],
        ]);

        $token = $tokenizer->tokenize($validated);

        return response()->json([
            'token'        => $token,
            'account_type' => strtolower($validated['account_type'] ?? 'checking'),
            'last4'        => substr($validated['account_number'], -4),
        ]);
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
            'invoice_status' => optional($paymentSession->invoice()->first())->status,
        ]);
    }
}
