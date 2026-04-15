<?php

namespace Modules\Outbound\Adapters;

use Modules\Outbound\Contracts\GatewayAdapterInterface;
use Modules\Outbound\DTOs\ChargeRequest;
use Modules\Outbound\DTOs\GatewayResponse;

class NmiAdapter implements GatewayAdapterInterface
{
    public function code(): string
    {
        return 'nmi';
    }

    public function charge(ChargeRequest $request): GatewayResponse
    {
        return GatewayResponse::declined('NMI adapter not implemented.');
    }
}
