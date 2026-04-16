<?php

namespace Modules\Payment\Services;

use Modules\Outbound\DTOs\ChargeRequest;
use Modules\Outbound\DTOs\GatewayResponse;
use Modules\Outbound\Factory\GatewayAdapterFactory;

class PaymentOrchestrator
{
    public function __construct(
        private readonly GatewayAdapterFactory $gatewayAdapterFactory,
    ) {}

    public function charge(string $gateway, ChargeRequest $request): GatewayResponse
    {
        return $this->gatewayAdapterFactory->make($gateway)->charge($request);
    }

    public function process(PaymentSession $session, string $token): void
    {
        // 1. Routing decision
        $ctx = new RoutingContext(
            merchantId:    $session->invoice->merchant_id,
            amountCents:   $session->invoice->amount_cents,
            paymentMethod: $session->payment_method,
            fundType:      $session->fund_type,
            sessionId:     $session->id,
        );
        $decision = $this->routingEngine->resolve($ctx);

        // 2. Build charge request
        $rule        = RoutingRule::find($decision->routingRuleId);
        $credentials = decrypt($rule->mid_credentials);
        $chargeReq   = new ChargeRequest(
            token:          $token,
            amountCents:    $session->invoice->amount_cents,
            currency:       $session->invoice->currency,
            midCredentials: $credentials,
            idempotencyKey: $session->idempotency_key,
        );

        // 3. Call gateway adapter
        $adapter  = $this->gatewayFactory->make($decision->gateway);
        AuditLogger::log('GATEWAY_CALL_STARTED', 'payment_session', $session->id);
        $response = $adapter->charge($chargeReq);
        AuditLogger::log('GATEWAY_CALL_COMPLETED', 'payment_session', $session->id,
            ['status' => $response->status]);

        // 4. Handle result
        if ($response->status === 'APPROVED') {
            $txn = null;
            DB::transaction(function () use (&$txn, $session, $response, $decision) {
                $txn = Transaction::create([
                    'payment_session_id' => $session->id,
                    'invoice_id'         => $session->invoice_id,
                    'routing_rule_id'    => $decision->routingRuleId,
                    'gateway'            => $decision->gateway,
                    'mid_id'             => $decision->midId,
                    'gateway_txn_id'     => $response->gatewayTxnId,
                    'status'             => 'CAPTURED',
                    'fund_type'          => $session->fund_type,
                    'amount_cents'       => $session->invoice->amount_cents,
                ]);
                $this->stateMachine->transition($session, 'COMPLETED');
                $session->invoice->update(['status' => 'PAID']);
                AuditLogger::log('PAYMENT_APPROVED', 'transaction', $txn->id);
            });
            // Fire event OUTSIDE the DB transaction
            event(new PaymentApproved($txn, $session, $session->invoice));
        } else {
            $this->stateMachine->transition($session, 'FAILED');
            event(new PaymentDeclined($session, $response));
        }
    }

}
