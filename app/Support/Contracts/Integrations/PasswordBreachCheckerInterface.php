<?php

namespace App\Support\Contracts\Integrations;

interface PasswordBreachCheckerInterface
{
    public function isPwned(string $password): bool;
}
