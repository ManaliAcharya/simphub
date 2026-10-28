<?php

namespace Modules\Payment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Models\PaymentSession;
use Modules\Outbound\Services\PayaAccountFormService;
use Modules\Outbound\Services\PayaTokenizerService;
use Modules\Routing\Models\RoutingRule;
use Modules\Payment\Services\CheckoutAuditTrail;
use Modules\Payment\Services\PaymentCheckoutService;
use RuntimeException;

class PaymentSessionController extends Controller
{
    public function __construct(
        private readonly CheckoutAuditTrail $audit,
    ) {}

    public function show(string $session, PaymentCheckoutService $checkout): JsonResponse
    {
        $paymentSession = PaymentSession::query()
            ->where('hosted_url_token', $session)
            ->firstOrFail();

        try {
            $details = $checkout->details($paymentSession);
        } catch (RuntimeException $exception) {
            $this->audit->record($paymentSession, 'CHECKOUT_LOAD_FAILED', [
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $this->audit->record($paymentSession, 'CHECKOUT_LOADED', [
            'payment_options' => collect($details['payment_options'])
                ->map(fn (array $option) => [
                    'gateway'         => $option['gateway'],
                    'payment_method'  => $option['payment_method'],
                    'routing_rule_id' => $option['routing_rule_id'],
                    'total_cents'     => $option['total_cents'],
                ])
                ->all(),
        ]);

        return response()->json($details);
    }

    public function payaFormUrl(string $session, PayaAccountFormService $accountForm): JsonResponse
    {
        $paymentSession = PaymentSession::query()
            ->where('hosted_url_token', $session)
            ->firstOrFail();

        if (! in_array($paymentSession->status, ['PENDING', 'AWAITING_PAYMENT', 'FAILED'], true)) {
            return $this->payaFormUnavailable($paymentSession, 'Payment session is not in a payable state.');
        }

        // Find active Paya routing rule to get mid credentials
        $rule = RoutingRule::query()
            ->where('gateway', 'paya')
            ->where('is_active', true)
            ->first();

        if (! $rule) {
            return $this->payaFormUnavailable($paymentSession, 'No active Paya routing rule found.');
        }

        try {
            $midCredentials = (array) ($rule->mid_credentials ?? []);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return $this->payaFormUnavailable($paymentSession, 'Routing rule credentials are corrupted. Please re-save the routing rule.');
        }

        // Optional invoice metadata for the form title
        $invoice = $paymentSession->invoice;
        $invoiceMeta = [
            'invoice_number' => $invoice?->invoice_number ?: $invoice?->external_invoice_id,
            'customer_name'  => null,
        ];

        try {
            $url = $accountForm->generateUrl($midCredentials, $invoiceMeta);
        } catch (\RuntimeException $e) {
            return $this->payaFormUnavailable($paymentSession, $e->getMessage());
        }

        $this->audit->record($paymentSession, 'PAYA_FORM_ISSUED', ['routing_rule_id' => $rule->id]);

        return response()->json(['url' => $url]);
    }

    public function tokenize(Request $request, string $session, PayaTokenizerService $tokenizer): JsonResponse
    {
        $paymentSession = PaymentSession::query()
            ->where('hosted_url_token', $session)
            ->firstOrFail();

        if (! in_array($paymentSession->status, ['PENDING', 'AWAITING_PAYMENT', 'FAILED'], true)) {
            $this->audit->record($paymentSession, 'BANK_DETAILS_REJECTED', [
                'message' => 'Payment session is not in a payable state.',
            ]);

            return response()->json(['message' => 'Payment session is not in a payable state.'], 422);
        }

        try {
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
        } catch (ValidationException $exception) {
            $this->audit->record($paymentSession, 'BANK_DETAILS_REJECTED', [
                'invalid_fields' => array_keys($exception->errors()),
            ]);

            throw $exception;
        }

        $token = $tokenizer->tokenize($validated);

        $this->audit->record($paymentSession, 'BANK_DETAILS_SECURED', [
            'account_type' => strtolower($validated['account_type'] ?? 'checking'),
            'last4'        => substr($validated['account_number'], -4),
        ]);

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

        // Recorded before anything can reject it, so every press of "Submit payment"
        // that reaches us is on record — whatever happens next.
        $this->audit->record($paymentSession, 'PAYMENT_SUBMITTED', [
            'payment_method'  => (string) ($request->input('payment_method') ?: 'CARD'),
            'routing_rule_id' => (string) $request->input('routing_rule_id'),
            'has_token'       => $request->filled('token'),
            'has_bank_token'  => $request->filled('paya_bank_token'),
        ]);

        // Reset a previously failed session so the state machine stays consistent:
        // FAILED → AWAITING_PAYMENT → PROCESSING (set by PaymentCheckoutService)
        if ($paymentSession->status === 'FAILED') {
            $paymentSession->forceFill(['status' => 'AWAITING_PAYMENT'])->save();
        }

        try {
            $request->validate([
                'token'                  => ['required', 'string'],
                'payment_method'         => ['nullable', 'string'],
                'routing_rule_id'        => ['required', 'string'],
                'paya_bank_token'        => ['nullable', 'string'],
                'first_name'             => ['required', 'string', 'max:100'],
                'last_name'              => ['required', 'string', 'max:100'],
                'billing_address'        => ['required', 'array'],
                'billing_address.address1' => ['required', 'string', 'max:255'],
                'billing_address.city'     => ['required', 'string', 'max:100'],
                'billing_address.state'    => ['required', 'string', 'max:2'],
                'billing_address.zip'      => ['required', 'string', 'max:10'],
            ]);
        } catch (ValidationException $exception) {
            $this->audit->record($paymentSession, 'PAYMENT_ATTEMPT_FAILED', [
                'stage'          => 'validation',
                'invalid_fields' => array_keys($exception->errors()),
            ]);

            throw $exception;
        }

        $extraBilling = [];
        if ($request->filled('paya_bank_token')) {
            $extraBilling = ['paya_bank_token' => (string) $request->input('paya_bank_token')];
        }

        if ($request->filled('first_name') || $request->filled('last_name')) {
            $extraBilling['first_name'] = (string) $request->input('first_name', '');
            $extraBilling['last_name']  = (string) $request->input('last_name', '');
            $extraBilling['billing_address'] = array_filter(
                (array) $request->input('billing_address', []),
                fn ($v) => $v !== null && trim((string) $v) !== ''
            );
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
            // The exact message the payer was shown.
            $this->audit->record($paymentSession->refresh(), 'PAYMENT_ATTEMPT_FAILED', [
                'stage'   => 'processing',
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            // Anything unexpected reaches the payer as a server error.
            $this->audit->record($paymentSession, 'PAYMENT_ATTEMPT_FAILED', [
                'stage'     => 'server_error',
                'exception' => get_class($exception),
                'message'   => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $this->audit->record($paymentSession->refresh(), 'PAYMENT_ATTEMPT_SUCCEEDED', [
            'transaction_id' => $transaction->id,
            'amount_cents'   => $transaction->amount_cents,
            'fee_cents'      => $transaction->fee_cents,
        ]);

        $invoice = $paymentSession->invoice()->first();

        return response()->json([
            'status'         => 'APPROVED',
            'transaction_id' => $transaction->id,
            'gateway_txn_id' => $transaction->gateway_txn_id,
            'invoice_status' => optional($invoice)->status,
            'redirect_url'   => optional($invoice)->success_redirect_url ?: null,
            // The actual charged amounts, straight from the completed transaction — the
            // confirmation page must display these, not recompute an estimate client-side,
            // since the real fee calculation (incl. any per-client exact-cent rounding) only
            // happens server-side and can diverge from a naive percent×amount guess.
            'amount_cents'   => $transaction->amount_cents,
            'fee_cents'      => $transaction->fee_cents,
        ]);
    }

    /**
     * Steps the checkout page reports from the payer's browser (see CheckoutAuditTrail).
     */
    public function event(Request $request, string $session): Response
    {
        $paymentSession = PaymentSession::query()
            ->where('hosted_url_token', $session)
            ->firstOrFail();

        $validated = $request->validate([
            'event'  => ['required', 'string', 'max:50'],
            'detail' => ['nullable', 'array'],
        ]);

        $this->audit->recordBrowserEvent($paymentSession, $validated['event'], (array) ($validated['detail'] ?? []));

        return response()->noContent();
    }

    private function payaFormUnavailable(PaymentSession $session, string $message): JsonResponse
    {
        $this->audit->record($session, 'PAYA_FORM_UNAVAILABLE', ['message' => $message]);

        return response()->json(['message' => $message], 422);
    }
}
