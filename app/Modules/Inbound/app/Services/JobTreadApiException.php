<?php

namespace Modules\Inbound\Services;

use RuntimeException;

/** A JobTread (Pave) request that failed: HTTP error, or an error body on a 2xx. */
class JobTreadApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly mixed $body = null)
    {
        parent::__construct($message);
    }

    /** Revoked, expired or wrong grant key. */
    public function isAuthFailure(): bool
    {
        if (in_array($this->status, [401, 403], true)) {
            return true;
        }

        $message = strtolower($this->getMessage());

        return str_contains($message, 'grant') && (str_contains($message, 'invalid') || str_contains($message, 'expired'));
    }

    /** The record doesn't exist (deleted), or the grant can't see it. */
    public function isNotFound(): bool
    {
        return $this->status === 404 || str_contains(strtolower($this->getMessage()), 'not found');
    }

    /** Rate limited or JobTread-side trouble: worth retrying later, not a bad request. */
    public function isTransient(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }
}
