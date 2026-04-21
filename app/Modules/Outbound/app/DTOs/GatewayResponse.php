<?php

namespace Modules\Outbound\DTOs;

readonly class GatewayResponse
{
    public function __construct(
        public bool $approved,
        public ?string $transactionReference = null,
        public ?string $message = null,
        public ?string $gatewayToken = null,
        public array $raw = [],
    ) {}

    public static function approved(string $transactionReference, ?string $gatewayToken = null, array $raw = []): self
    {
        return new self(true, $transactionReference, null, $gatewayToken, $raw);
    }

    public static function declined(string $message, ?string $gatewayToken = null, array $raw = []): self
    {
        return new self(false, null, $message, $gatewayToken, $raw);
    }
}
