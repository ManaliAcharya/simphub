<?php

namespace Modules\Outbound\Factory;

use InvalidArgumentException;
use Modules\Outbound\Adapters\FluidPayAdapter;
use Modules\Outbound\Adapters\NmiAdapter;
use Modules\Outbound\Adapters\PayaAdapter;
use Modules\Outbound\Contracts\GatewayAdapterInterface;

class GatewayAdapterFactory
{
    public function make(string $gateway): GatewayAdapterInterface
    {
        return match ($gateway) {
            'nmi' => app(NmiAdapter::class),
            'fluidpay' => app(FluidPayAdapter::class),
            'paya' => app(PayaAdapter::class),
            default => throw new InvalidArgumentException("Unsupported gateway [{$gateway}]."),
        };
    }
}
