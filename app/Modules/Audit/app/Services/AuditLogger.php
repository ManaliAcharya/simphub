<?php

namespace Modules\Audit\Services;

use Illuminate\Support\Facades\Log;

class AuditLogger
{
    public function log(string $action, array $context = []): void
    {
        Log::info($action, $context);
    }
}
