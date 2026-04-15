<?php

namespace Modules\Payment\Services;

class IdempotencyService
{
    public function claim(string $key): bool
    {
        return $key !== '';
    }
}
