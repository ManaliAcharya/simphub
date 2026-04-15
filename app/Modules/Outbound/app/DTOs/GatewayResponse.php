<?php

namespace Modules\Outbound\DTOs;

readonly class GatewayResponse
{
    public function __construct(
        public bool $approved,
        public ?string $transactionReference = null,
        public ?string $message = null,
        public array $raw = [],
    ) {}

    public static function approved(string $transactionReference, array $raw = []): self
    {
        return new self(true, $transactionReference, null, $raw);
    }

    public static function declined(string $message, array $raw = []): self
    {
        return new self(false, null, $message, $raw);
    }
}
