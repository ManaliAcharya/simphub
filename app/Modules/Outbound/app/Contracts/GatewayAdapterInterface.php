<?php

namespace Modules\Outbound\Contracts;

use Modules\Outbound\DTOs\ChargeRequest;
use Modules\Outbound\DTOs\GatewayResponse;

interface GatewayAdapterInterface
{
    public function code(): string;

    public function charge(ChargeRequest $request): GatewayResponse;
}
