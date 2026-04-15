<?php

namespace Modules\Inbound\DTOs;

readonly class WebhookEvent
{
    public function __construct(
        public string $source,
        public string $eventName,
        public array $payload = [],
        public array $headers = [],
    ) {}
}
