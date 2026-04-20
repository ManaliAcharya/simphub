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
        $decision = $this->routing->decide(new RoutingContext(
            merchantId: 'default',
            amountInCents: (int) $invoice->amount_cents,
            paymentMethod: 'CARD',
            fundType: (string) $session->fund_type,
            sessionId: (string) $session->id,
            currency: (string) $invoice->currency,
        ));

        $hostedFields = $this->gateways->make($decision->gateway)->hostedFieldsConfig($decision->mid);

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
            'hosted_fields' => [
                'gateway' => $hostedFields->gateway,
                'fields' => $hostedFields->fields,
                'metadata' => $hostedFields->metadata,
            ],
        ];
    }

}
