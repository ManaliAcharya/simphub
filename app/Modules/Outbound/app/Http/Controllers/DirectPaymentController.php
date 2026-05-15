<?php

namespace Modules\Outbound\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Billing\Models\Transaction;
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
            $payaToken = $this->tokenizer->tokenizeViaPaya([
                'routing_number' => '490000018',
                'account_number' => '123456789',
                'account_type'   => 'checking',
            ], $decision->midCredentials);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'Tokenization failed: ' . $e->getMessage()], 502);
        }

        $idempotencyKey = md5($merchantId . $payaToken . $validated['amount_cents'] . now()->toDateTimeString());

        $chargeRequest = new ChargeRequest(
            token:          $payaToken,
            amountInCents:  (int) $validated['amount_cents'],
            currency:       $currency,
            idempotencyKey: $idempotencyKey,
            midCredentials: $decision->midCredentials,
            billing:        ['paya_token' => $payaToken],
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

        $transaction = Transaction::create([
            'routing_rule_id' => $decision->routingRuleId,
            'gateway'         => $decision->gateway,
            'mid'             => $decision->mid,
            'gateway_txn_id'  => $response->transactionReference,
            'gateway_token'   => $payaToken,
            'status'          => 'CAPTURED',
            'fund_type'       => $fundType,
            'amount_cents'    => (int) $validated['amount_cents'],
            'currency'        => $currency,
            'gateway_response'=> $response->raw,
        ]);

        return response()->json([
            'status'         => 'APPROVED',
            'transaction_id' => $transaction->id,
            'gateway_txn_id' => $response->transactionReference,
            'gateway'        => $decision->gateway,
            'amount_cents'   => (int) $validated['amount_cents'],
            'currency'       => $currency,
            'payment_method' => $paymentMethod,
        ]);
    }
}
