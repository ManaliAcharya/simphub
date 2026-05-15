<?php

namespace Modules\Outbound\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Models\Client;
use Modules\Outbound\DTOs\ChargeRequest;
use Modules\Outbound\Factory\GatewayAdapterFactory;
use Modules\Outbound\Services\PayaTokenizerService;
use Modules\Routing\DTOs\RoutingContext;
use Modules\Routing\Services\RoutingEngine;

class DirectPaymentController extends Controller
{
    public function __construct(
        private readonly RoutingEngine $routing,
        private readonly GatewayAdapterFactory $gateways,
        private readonly PayaTokenizerService $tokenizer,
    ) {}

    public function charge(Request $request, string $merchantId): JsonResponse
    {
        $client = Client::query()
            ->where('pms_client_id', $merchantId)
            ->first();

        if (! $client) {
            return response()->json(['error' => 'Merchant not found.'], 404);
        }

        $validated = $request->validate([
            'amount_cents'   => ['required', 'integer', 'min:1'],
            'token'          => ['required', 'string'],
            'payment_method' => ['nullable', 'string', 'in:ACH,CARD'],
            'fund_type'      => ['nullable', 'string', 'in:OPERATING,TRUST'],
            'currency'       => ['nullable', 'string', 'size:3'],
        ]);

        $paymentMethod = strtoupper($validated['payment_method'] ?? 'ACH');
        $fundType      = strtoupper($validated['fund_type']      ?? 'OPERATING');
        $currency      = strtoupper($validated['currency']       ?? 'USD');

        try {
            $decision = $this->routing->decide(new RoutingContext(
                merchantId:    'default',
                amountInCents: (int) $validated['amount_cents'],
                paymentMethod: $paymentMethod,
                fundType:      $fundType,
                sessionId:     '',
                currency:      $currency,
            ));
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        try {
            $billing = $this->tokenizer->detokenize($validated['token']);
        } catch (\RuntimeException) {
            // Token is a raw Paya gateway token, not a Laravel-encrypted one
            $billing = ['paya_token' => $validated['token']];
        }

        $chargeRequest = new ChargeRequest(
            token:          $validated['token'],
            amountInCents:  (int) $validated['amount_cents'],
            currency:       $currency,
            idempotencyKey: md5($merchantId . $validated['token'] . $validated['amount_cents'] . now()->toDateTimeString()),
            midCredentials: $decision->midCredentials,
            billing:        $billing,
        );

        try {
            $adapter  = $this->gateways->make($decision->gateway);
            $response = $adapter->charge($chargeRequest);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        if (! $response->approved) {
            return response()->json([
                'status'  => 'DECLINED',
                'message' => $response->message,
                'gateway' => $decision->gateway,
            ], 422);
        }

        return response()->json([
            'status'          => 'APPROVED',
            'gateway_txn_id'  => $response->transactionReference,
            'gateway'         => $decision->gateway,
            'amount_cents'    => (int) $validated['amount_cents'],
            'currency'        => $currency,
            'payment_method'  => $paymentMethod,
        ]);
    }
}
