<?php

namespace App\Support\Providers;

use App\Support\Contracts\Integrations\CaptchaVerifierInterface;
use App\Support\Contracts\Integrations\PasswordBreachCheckerInterface;
use App\Support\Integrations\HIBP\PwnedPasswordService;
use App\Support\Integrations\Turnstile\TurnstileService;
use Illuminate\Support\ServiceProvider;

class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            PasswordBreachCheckerInterface::class,
            PwnedPasswordService::class
        );
        $this->app->bind(
            CaptchaVerifierInterface::class,
            TurnstileService::class
        );
    }
}
