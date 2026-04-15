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
}
