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
        $candidates = $this->candidates($context);

        if ($candidates->isEmpty()) {
            throw new RuntimeException('No routing rule available for this payment session.');
        }

        return $candidates->first();
    }

    public function candidates(RoutingContext $context)
    {
        return RoutingRule::query()
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
            ->get()
            ->map(fn (RoutingRule $rule) => $this->mapRuleToDecision($rule));
    }

    public function findCandidate(RoutingContext $context, string $routingRuleId): RoutingDecision
    {
        $decision = $this->candidates($context)
            ->first(fn (RoutingDecision $candidate) => $candidate->routingRuleId === $routingRuleId);

        if (! $decision) {
            throw new RuntimeException('Selected routing rule is not available for this payment session.');
        }

        return $decision;
    }

    private function mapRuleToDecision(RoutingRule $rule): RoutingDecision
    {
        $midCredentials = [];

        try {
            $midCredentials = (array) ($rule->mid_credentials ?? []);
        } catch (\Throwable $e) {
            \Log::error('RoutingRule: mid_credentials decrypt failed', [
                'routing_rule_id' => $rule->id,
                'gateway'         => $rule->gateway,
                'mid'             => $rule->mid,
                'error'           => $e->getMessage(),
            ]);
            $midCredentials = [];
        }

        \Log::debug('RoutingEngine: rule resolved to decision', [
            'routing_rule_id'    => $rule->id,
            'gateway'            => $rule->gateway,
            'mid'                => $rule->mid,
            'environment'        => $midCredentials['environment'] ?? 'sandbox',
            'has_credential_override' => ! empty($midCredentials),
            'priority'           => $rule->priority,
            'is_fallback'        => $rule->is_fallback,
        ]);

        return new RoutingDecision(
            gateway: (string) $rule->gateway,
            mid: (string) $rule->mid,
            routingRuleId: (string) $rule->id,
            midCredentials: $midCredentials,
            ruleMatches: [
                'merchant_id' => $rule->merchant_id,
                'payment_method' => $rule->payment_method,
                'fund_type' => $rule->fund_type,
            ],
        );
    }
}
