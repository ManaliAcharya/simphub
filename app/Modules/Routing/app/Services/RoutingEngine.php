<?php

namespace Modules\Routing\Services;

use Modules\Routing\DTOs\RoutingContext;
use Modules\Routing\DTOs\RoutingDecision;

class RoutingEngine
{
    public function decide(RoutingContext $context): RoutingDecision
    {
        return new RoutingDecision(gateway: 'nmi');
    }
}
