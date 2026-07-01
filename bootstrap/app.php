<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Modules\Auth\Http\Middleware\MerchantSessionMiddleware;
use Modules\Auth\Http\Middleware\PreventBackHistory;
use Modules\Auth\Http\Middleware\RedirectIfMerchantAuthenticated;
use Modules\Auth\Http\Middleware\RequireReauthentication;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'no-cache'        => PreventBackHistory::class,
            'merchant.guest'  => RedirectIfMerchantAuthenticated::class,
            'merchant.auth'   => MerchantSessionMiddleware::class,
            'reauth.required' => RequireReauthentication::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
