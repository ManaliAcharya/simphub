<?php

namespace Modules\Audit\Services;

use Illuminate\Support\Str;

/**
 * One trace id per request or queued job (bound as scoped), so every audit row
 * written while handling it can be grouped back together.
 */
class TraceContext
{
    private ?string $traceId = null;

    public function current(): string
    {
        return $this->traceId ??= (string) Str::uuid();
    }
}
