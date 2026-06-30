<?php

namespace App\Support\Contracts\Integrations;

interface CaptchaVerifierInterface
{
    public function verify(string $token, ?string $ipAddress = null): bool;
}
