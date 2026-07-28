<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\RateLimiter;
use Modules\Inbound\Exceptions\AdvancedMdRateLimitExceededException;

/**
 * Enforces AdvancedMD's published rate-limit policy, per office key, over
 * rolling 1-minute windows:
 *
 *   Tier 1 (high impact)   — GETUPDATEDVISITS, GETUPDATEDPATIENTS
 *   Tier 2 (medium impact) — SAVECHARGES, GETDEMOGRAPHIC, GETDATEVISITS,
 *                            UPDVISITWITHNEWCHARGES, GETTXHISTORY, GETAPPTS,
 *                            GETPAYMENTDETAILDATA
 *   Tier 3 (low impact)    — all LOOKUP APIs, and anything not listed above
 *
 * Peak hours are Mon–Fri 6:00 AM–6:00 PM Mountain Time; limits are looser
 * off-peak. Call throttle() immediately before making the outbound request —
 * it throws rather than blocking, since AMD calls here run inside queued
 * jobs / synchronous listeners where sleeping until the window resets could
 * exceed the queue worker's own timeout.
 */
class AdvancedMdRateLimiter
{
    private const TIER_1_ACTIONS = [
        'getupdatedvisits',
        'getupdatedpatients',
    ];

    private const TIER_2_ACTIONS = [
        'savecharges',
        'getdemographic',
        'getdatevisits',
        'updvisitwithnewcharges',
        'gettxhistory',
        'getappts',
        'getpaymentdetaildata',
    ];

    /** @var array<int, array{peak: int, offpeak: int}> */
    private const TIER_LIMITS = [
        1 => ['peak' => 1,  'offpeak' => 60],
        2 => ['peak' => 12, 'offpeak' => 120],
        3 => ['peak' => 24, 'offpeak' => 120],
    ];

    /**
     * @throws AdvancedMdRateLimitExceededException when this office key has
     *         already used up its per-minute allowance for the action's tier.
     */
    public function throttle(string $officeKey, string $action): void
    {
        $tier  = $this->tierFor($action);
        $limit = $this->limitFor($tier);
        $key   = "advancedmd_rl:{$officeKey}:tier{$tier}";

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw new AdvancedMdRateLimitExceededException(
                "AdvancedMD Tier {$tier} rate limit ({$limit}/min) would be exceeded for office {$officeKey} (action: {$action}).",
                RateLimiter::availableIn($key),
            );
        }

        RateLimiter::hit($key, 60);
    }

    private function tierFor(string $action): int
    {
        $action = strtolower($action);

        return match (true) {
            in_array($action, self::TIER_1_ACTIONS, true) => 1,
            in_array($action, self::TIER_2_ACTIONS, true) => 2,
            default => 3,
        };
    }

    private function limitFor(int $tier): int
    {
        return self::TIER_LIMITS[$tier][$this->isPeakHours() ? 'peak' : 'offpeak'];
    }

    private function isPeakHours(): bool
    {
        $now = now('America/Denver');

        return $now->isWeekday() && $now->hour >= 6 && $now->hour < 18;
    }
}
