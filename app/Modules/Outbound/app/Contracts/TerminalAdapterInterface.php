<?php

namespace Modules\Outbound\Contracts;

interface TerminalAdapterInterface
{
    public function code(): string;

    public function process(string $merchantId, array $payload, array $credentials = []): array;
}
