<?php

namespace Modules\Inbound\Exceptions;

use RuntimeException;

/**
 * Thrown when we would exceed AdvancedMD's own published per-office-key,
 * per-minute call allowance for a given API tier — before the call is made,
 * so we never actually send the request that would trip AMD's limiter.
 */
class AdvancedMdRateLimitExceededException extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds)
    {
        parent::__construct($message);
    }
}
