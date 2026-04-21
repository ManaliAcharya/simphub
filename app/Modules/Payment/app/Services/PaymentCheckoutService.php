<?php

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Models\Transaction;
use Modules\Billing\States\SessionStateMachine;
use Modules\Outbound\DTOs\ChargeRequest;
use Modules\Outbound\Factory\GatewayAdapterFactory;
use Modules\Payment\Events\PaymentApproved;
use Modules\Routing\DTOs\RoutingContext;
use Modules\Routing\Services\RoutingEngine;
use RuntimeException;

class PaymentCheckoutService
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly GatewayAdapterFactory $gateways,
        private readonly RoutingEngine $routing,
        private readonly SessionStateMachine $stateMachine,
    ) {}

    public function details(PaymentSession $session): array
    {
        $invoice = $session->invoice()->firstOrFail();
        $options = $this->routing->candidates($this->makeRoutingContext($session, 'CARD'));

        if ($options->isEmpty()) {
            throw new RuntimeException('No routing rule available for this payment session.');
        }

        return [
            'session' => [
                'id' => $session->id,
                'token' => $session->hosted_url_token,
                'status' => $session->status,
                'expires_at' => optional($session->expires_at)->toIso8601String(),
            ],
            'invoice' => [
                'id' => $invoice->id,
                'external_invoice_id' => $invoice->external_invoice_id,
                'amount_cents' => $invoice->amount_cents,
                'currency' => $invoice->currency,
                'fund_type' => $invoice->fund_type,
                'status' => $invoice->status,
                'client_emails' => array_values(array_filter((array) $invoice->recipient_emails)),
            ],
            'payment_options' => $options->map(function ($decision) {
                $hostedFields = $this->gateways->make($decision->gateway)->hostedFieldsConfig($decision->mid);

                return [
                    'routing_rule_id' => $decision->routingRuleId,
                    'gateway' => $decision->gateway,
                    'mid' => $decision->mid,
                    'rule_matches' => $decision->ruleMatches,
                    'hosted_fields' => [
                        'gateway' => $hostedFields->gateway,
                        'fields' => $hostedFields->fields,
                        'metadata' => $hostedFields->metadata,
                    ],
                ];
            })->values()->all(),
        ];
    }

    public function submit(
        PaymentSession $session,
        string $token,
        string $paymentMethod = 'CARD',
        ?string $routingRuleId = null
    ): Transaction {
        $invoice = $session->invoice()->firstOrFail();

        if ($token === '') {
            throw new RuntimeException('Payment token is required.');
        }

        if (! $routingRuleId) {
            throw new RuntimeException('Payment option selection is required.');
        }

        if ($session->status === 'COMPLETED') {
            $existing = Transaction::query()
                ->where('payment_session_id', $session->id)
                ->latest('created_at')
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $existing = $this->idempotency->acquireLock((string) $session->idempotency_key);

        if ($existing) {
            return $existing;
        }

        $session->forceFill([
            'status' => 'PROCESSING',
            'payment_method' => $paymentMethod,
        ])->save();

        $routingContext = $this->makeRoutingContext($session, $paymentMethod);
        $decision = $this->routing->findCandidate($routingContext, $routingRuleId);

        AuditLogger::log('ROUTING_DECISION_MADE', 'payment_session', $session->id, [
            'gateway' => $decision->gateway,
            'mid' => $decision->mid,
            'routing_rule_id' => $decision->routingRuleId,
        ]);

        $response = $this->gateways->make($decision->gateway)->charge(new ChargeRequest(
            token: $token,
            amountInCents: (int) $invoice->amount_cents,
            currency: (string) $invoice->currency,
            idempotencyKey: (string) $session->idempotency_key,
            metadata: [
                'invoice_id' => $invoice->id,
                'payment_session_id' => $session->id,
                'routing_rule_id' => $routingRuleId,
            ],
        ));

        if (! $response->approved) {
            $session->forceFill(['status' => 'FAILED'])->save();
            $this->idempotency->fail((string) $session->idempotency_key);
            AuditLogger::log('PAYMENT_DECLINED', 'payment_session', $session->id, [
                'message' => $response->message,
                'gateway' => $decision->gateway,
            ]);

            throw new RuntimeException($response->message ?? 'Payment was declined.');
        }

        $transaction = DB::transaction(function () use ($session, $invoice, $decision, $response) {
            $transaction = Transaction::query()->create([
                'payment_session_id' => $session->id,
                'invoice_id' => $invoice->id,
                'routing_rule_id' => $decision->routingRuleId,
                'gateway' => $decision->gateway,
                'mid' => $decision->mid,
                'gateway_txn_id' => $response->transactionReference,
                'gateway_token' => (string) $response->gatewayToken,
                'status' => 'CAPTURED',
                'fund_type' => $session->fund_type,
                'amount_cents' => $invoice->amount_cents,
                'currency' => $invoice->currency,
                'gateway_response' => $response->raw,
            ]);

            $this->stateMachine->transition($session, 'COMPLETED');
            $session->forceFill([
                'status' => 'COMPLETED',
                'completed_at' => now(),
            ])->save();

            $invoice->forceFill([
                'status' => 'PAID',
                'pms_sync_status' => 'SYNCED',
            ])->save();

            $this->idempotency->complete((string) $session->idempotency_key, $transaction);

            AuditLogger::log('PAYMENT_APPROVED', 'transaction', $transaction->id, [
                'payment_session_id' => $session->id,
                'gateway' => $decision->gateway,
                'gateway_txn_id' => $response->transactionReference,
            ]);

            return $transaction;
        });

        event(new PaymentApproved((string) $transaction->id, [
            'invoice_id' => $invoice->id,
            'payment_session_id' => $session->id,
        ]));

        return $transaction;
    }

    private function makeRoutingContext(PaymentSession $session, string $paymentMethod): RoutingContext
    {
        $invoice = $session->invoice()->firstOrFail();

        return new RoutingContext(
            'default',
            (int) $invoice->amount_cents,
            $paymentMethod,
            (string) $session->fund_type,
            (string) $session->id,
            (string) $invoice->currency,
            [],
        );
    }
}
