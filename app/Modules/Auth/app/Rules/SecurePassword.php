<?php

namespace Modules\Auth\Rules;

use App\Support\Contracts\Integrations\PasswordBreachCheckerInterface;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SecurePassword implements ValidationRule
{
    public function __construct(
        private readonly ?string $email = null,
        private readonly ?string $companyName = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $password = (string) $value;

        if (mb_strlen($password) < 12) {
            $fail('The password must be at least 12 characters.');
            return;
        }

        if ($this->containsEmailLocalPart($password)) {
            $fail('The password must not contain your email name.');
            return;
        }

        if ($this->containsCompanyName($password)) {
            $fail('The password must not contain the company name.');
            return;
        }

        $checker = app(PasswordBreachCheckerInterface::class);

        if ($checker->isPwned($password)) {
            $fail('This password is not secure. Please choose a different password.');
        }
    }

    private function containsEmailLocalPart(string $password): bool
    {
        if (! $this->email || ! str_contains($this->email, '@')) {
            return false;
        }

        $localPart = strtolower(strtok($this->email, '@'));

        return $localPart !== '' && str_contains(strtolower($password), $localPart);
    }

    private function containsCompanyName(string $password): bool
    {
        if (! $this->companyName) {
            return false;
        }

        $cleanPassword = strtolower(preg_replace('/[^a-z0-9]/i', '', $password));
        $cleanCompany = strtolower(preg_replace('/[^a-z0-9]/i', '', $this->companyName));

        return $cleanCompany !== '' && str_contains($cleanPassword, $cleanCompany);
    }
}
