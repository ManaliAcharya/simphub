<?php

namespace Modules\Outbound\Adapters;

use Modules\Outbound\Contracts\GatewayAdapterInterface;
use Modules\Outbound\DTOs\ChargeRequest;
use Modules\Outbound\DTOs\GatewayResponse;
use Modules\Outbound\DTOs\HostedFieldsConfig;
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

    public function hostedFieldsConfig(string $mid, array $midCredentials = []): HostedFieldsConfig
    {
        return new HostedFieldsConfig(
            gateway: 'paya',
            fields: [
                'button_label' => 'Pay with Paya',
            ],
            metadata: [
                'mid' => $mid,
                'mode' => 'direct',
                'payment_method' => 'ACH',
            ],
        );
    }
}
