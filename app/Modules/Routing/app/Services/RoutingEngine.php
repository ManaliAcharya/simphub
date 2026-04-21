<?php

namespace Modules\Routing\Services;

use Modules\Routing\DTOs\RoutingContext;
use Modules\Routing\DTOs\RoutingDecision;
use Modules\Routing\Models\RoutingRule;
use RuntimeException;

class RoutingEngine
{
    public function decide(RoutingContext $context): RoutingDecision
    {
        $rule = RoutingRule::query()
            ->where('is_active', true)
            ->where('merchant_id', $context->merchantId)
            ->whereIn('payment_method', [$context->paymentMethod, 'ANY'])
            ->whereIn('fund_type', [$context->fundType, 'ANY'])
            ->where('min_amount_cents', '<=', $context->amountInCents)
            ->where(function ($query) use ($context) {
                $query->whereNull('max_amount_cents')
                    ->orWhere('max_amount_cents', '>=', $context->amountInCents);
            })
            ->orderBy('is_fallback')
            ->orderBy('priority')
            ->first();

        if (! $rule) {
            throw new RuntimeException('No routing rule available for this payment session.');
        }

        return new RoutingDecision(
            gateway: (string) $rule->gateway,
            mid: (string) $rule->mid,
            routingRuleId: (string) $rule->id,
            ruleMatches: [
                'merchant_id' => $rule->merchant_id,
                'payment_method' => $rule->payment_method,
                'fund_type' => $rule->fund_type,
            ],
        );
    }
}
