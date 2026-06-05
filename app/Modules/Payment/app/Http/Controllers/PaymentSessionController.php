<?php

namespace Modules\Payment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Billing\Models\PaymentSession;
use Modules\Outbound\Services\PayaAccountFormService;
use Modules\Outbound\Services\PayaTokenizerService;
use Modules\Routing\Models\RoutingRule;
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

    public function payaFormUrl(string $session, PayaAccountFormService $accountForm): JsonResponse
    {
        $paymentSession = PaymentSession::query()
            ->where('hosted_url_token', $session)
            ->firstOrFail();

        if (! in_array($paymentSession->status, ['PENDING', 'AWAITING_PAYMENT'], true)) {
            return response()->json(['message' => 'Payment session is not in a payable state.'], 422);
        }

        // Find active Paya routing rule to get mid credentials
        $rule = RoutingRule::query()
            ->where('gateway', 'paya')
            ->where('is_active', true)
            ->first();

        if (! $rule) {
            return response()->json(['message' => 'No active Paya routing rule found.'], 422);
        }

        $midCredentials = is_string($rule->mid_credentials)
            ? json_decode(decrypt($rule->mid_credentials), true) ?? []
            : (array) ($rule->mid_credentials ?? []);

        // Optional invoice metadata for the form title
        $invoice = $paymentSession->invoice;
        $invoiceMeta = [
            'invoice_number' => $invoice?->invoice_number ?: $invoice?->external_invoice_id,
            'customer_name'  => null,
        ];

        try {
            $url = $accountForm->generateUrl($midCredentials, $invoiceMeta);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['url' => $url]);
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
            'token'           => ['required', 'string'],
            'payment_method'  => ['nullable', 'string'],
            'routing_rule_id' => ['required', 'string'],
            'paya_bank_token' => ['nullable', 'string'],
        ]);

        $extraBilling = [];
        if ($request->filled('paya_bank_token')) {
            $extraBilling = ['paya_bank_token' => (string) $request->input('paya_bank_token')];
        }

        try {
            $transaction = $checkout->submit(
                session: $paymentSession,
                token: (string) $request->string('token'),
                paymentMethod: (string) ($request->input('payment_method') ?: 'CARD'),
                routingRuleId: (string) $request->string('routing_rule_id'),
                extraBilling: $extraBilling,
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $invoice = $paymentSession->invoice()->first();

        return response()->json([
            'status'         => 'APPROVED',
            'transaction_id' => $transaction->id,
            'gateway_txn_id' => $transaction->gateway_txn_id,
            'invoice_status' => optional($invoice)->status,
            'redirect_url'   => optional($invoice)->success_redirect_url ?: null,
        ]);
    }
}
