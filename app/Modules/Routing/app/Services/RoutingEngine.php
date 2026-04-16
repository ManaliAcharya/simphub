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

    public function resolve(RoutingContext $ctx): RoutingDecision
    {
        $candidates = RoutingRule::query()
            ->where('is_active', true)
            ->where('merchant_id', $ctx->merchantId)
            ->where(fn($q) => $q->where('payment_method', $ctx->paymentMethod)
                               ->orWhere('payment_method', 'ANY'))
            ->where(fn($q) => $q->where('fund_type', $ctx->fundType)
                               ->orWhere('fund_type', 'ANY'))
            ->where('min_amount_cents', '<=', $ctx->amountCents)
            ->where(fn($q) => $q->whereNull('max_amount_cents')
                               ->orWhere('max_amount_cents', '>=', $ctx->amountCents))
            ->orderBy('is_fallback')   // primary rules first
            ->orderBy('priority')
            ->get();

        foreach ($candidates as $rule) {
            if ($this->volumeLimitOk($rule)) {
                AuditLogger::log('ROUTING_DECISION_MADE', 'transaction',
                    $ctx->sessionId, ['gateway' => $rule->gateway, 'mid' => $rule->mid_id]);
                return new RoutingDecision($rule->gateway, $rule->mid_id, $rule->id);
            }
        }
        throw new NoRouteAvailableException($ctx);
    }

    private function volumeLimitOk(RoutingRule $rule): bool
    {
        if (! $rule->daily_volume_limit_cents) return true;
        $used = Cache::get('vol:' . $rule->id . ':' . today()->format('Ymd'), 0);
        return $used < $rule->daily_volume_limit_cents;
    }

}
