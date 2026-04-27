<?php

namespace Modules\Inbound\DTOs;

use Modules\Inbound\Models\PmsConnection;

readonly class PmsCallbackResult
{
    public function __construct(
        public PmsConnection $connection,
        public string $successMessage,
        public array $redirectParameters = [],
    ) {}
}
