<?php

namespace Modules\Outbound\Adapters;

use Modules\Outbound\Contracts\GatewayAdapterInterface;
use Modules\Outbound\DTOs\ChargeRequest;
use Modules\Outbound\DTOs\GatewayResponse;
use Modules\Outbound\DTOs\HostedFieldsConfig;
use Modules\Outbound\DTOs\RefundRequest;
use Modules\Outbound\Services\PayaSandboxChargeService;

class PayaAdapter implements GatewayAdapterInterface
{
    public function __construct(
        private readonly PayaSandboxChargeService $sandbox,
    ) {}

    public function code(): string
    {
        return 'paya';
    }

    public function charge(ChargeRequest $request): GatewayResponse
    {
        $result = $this->sandbox->charge($request, $request->midCredentials);

        if (! $result['approved']) {
            return GatewayResponse::declined(
                $result['message'] ?: 'Paya sandbox payment declined.',
                gatewayToken: $request->token,
                raw: $result,
            );
        }

        return GatewayResponse::approved(
            transactionReference: $result['transaction_id'] ?: ('paya-'.$request->idempotencyKey),
            gatewayToken: $request->token,
            raw: $result,
        );
    }

    public function refund(RefundRequest $request): GatewayResponse
    {
        // Paya ACH credit = same SOAP flow as a debit but with a positive amount (no minus sign).
        // We re-use the existing charge path with transactionType='credit' and the stored paya token.
        $chargeRequest = new ChargeRequest(
            token: $request->gatewayToken,
            amountInCents: $request->amountInCents,
            currency: $request->currency,
            idempotencyKey: '',
            midCredentials: $request->midCredentials,
            billing: ['paya_token' => $request->gatewayToken],
            metadata: $request->metadata,
            transactionType: 'credit',
        );

        $result = $this->sandbox->charge($chargeRequest, $request->midCredentials);

        if (! $result['approved']) {
            return GatewayResponse::declined(
                $result['message'] ?: 'Paya refund declined.',
                gatewayToken: $request->gatewayToken,
                raw: $result,
            );
        }

        return GatewayResponse::approved(
            transactionReference: $result['transaction_id'] ?: ('paya-refund-'.uniqid()),
            gatewayToken: $request->gatewayToken,
            raw: $result,
        );
    }

    public function void(string $gatewayTxnId, array $midCredentials = []): GatewayResponse
    {
        // Paya WSDL (both sandbox and production) has no void/cancel operation.
        return GatewayResponse::declined('Void is not supported for Paya ACH transactions.');
    }

    public function listTransactions(array $filters, array $midCredentials = []): array
    {
        // Paya WSDL has no transaction-list or search operation (only GetArchivedResponse by single ID).
        return ['data' => [], 'total_count' => 0];
    }

    public function hostedFieldsConfig(string $mid, array $midCredentials = []): HostedFieldsConfig
    {
        // Use the hosted AccountForm iframe only when developer credentials are configured.
        // Without them, fall back to 'direct' mode which auto-tokenises the static
        // PAYA_ROUTING_NUMBER / PAYA_ACCOUNT_NUMBER from .env via PaymentCheckoutService.
        $mode = env('PAYA_DEVELOPER_ID') ? 'paya_ach' : 'direct';

        return new HostedFieldsConfig(
            gateway: 'paya',
            fields: [
                'button_label' => 'Pay with Paya',
            ],
            metadata: [
                'mid'            => $mid,
                'mode'           => $mode,
                'payment_method' => 'ACH',
            ],
        );
    }
}
