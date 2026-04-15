<?php

namespace Modules\Routing\DTOs;

readonly class RoutingDecision
{
    public function __construct(
        public string $gateway,
        public array $ruleMatches = [],
    ) {}
}
