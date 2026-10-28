<?php

namespace Modules\Outbound\DTOs;

readonly class AchStatus
{
    public const PENDING  = 'PENDING';
    public const SETTLED  = 'SETTLED';
    public const RETURNED = 'RETURNED';

    public function __construct(
        public string $status,
        public ?string $returnCode = null,
        public ?string $returnReason = null,
        public array $raw = [],
    ) {}
}
