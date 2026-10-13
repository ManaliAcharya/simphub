<?php

namespace Modules\Inbound\Exceptions;

use RuntimeException;

/** A subscription API failure whose code is the HTTP status to return. */
class SubscriptionException extends RuntimeException
{
    public function status(): int
    {
        return $this->getCode() ?: 422;
    }
}
