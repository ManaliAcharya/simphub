<?php

namespace Modules\Routing\DTOs;

readonly class RoutingDecision
{
    public function __construct(
        public string $gateway,
        public string $mid,
        public string $routingRuleId,
        public array $midCredentials = [],
        public array $ruleMatches = [],
    ) {}
}
