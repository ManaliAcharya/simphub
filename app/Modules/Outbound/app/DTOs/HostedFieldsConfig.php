<?php

namespace Modules\Outbound\DTOs;

readonly class HostedFieldsConfig
{
    public function __construct(
        public string $gateway,
        public array $fields = [],
        public array $metadata = [],
    ) {}
}
