<?php

namespace Modules\Outbound\Adapters;

use Modules\Outbound\Contracts\GatewayAdapterInterface;
use Modules\Outbound\DTOs\ChargeRequest;
use Modules\Outbound\DTOs\GatewayResponse;

class PayaAdapter implements GatewayAdapterInterface
{
    public function code(): string
    {
        return 'paya';
    }

    public function charge(ChargeRequest $request): GatewayResponse
    {
        return GatewayResponse::declined('Paya adapter not implemented.');
    }
}
