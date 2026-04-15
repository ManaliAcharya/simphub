<?php

namespace Modules\Outbound\Adapters;

use Modules\Outbound\Contracts\GatewayAdapterInterface;
use Modules\Outbound\DTOs\ChargeRequest;
use Modules\Outbound\DTOs\GatewayResponse;

class FluidPayAdapter implements GatewayAdapterInterface
{
    public function code(): string
    {
        return 'fluidpay';
    }

    public function charge(ChargeRequest $request): GatewayResponse
    {
        return GatewayResponse::declined('FluidPay adapter not implemented.');
    }
}
