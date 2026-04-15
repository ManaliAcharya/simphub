<?php

namespace Modules\Audit\Services;

use Illuminate\Support\Str;

class TraceContext
{
    public function current(): string
    {
        return (string) Str::uuid();
    }
}
