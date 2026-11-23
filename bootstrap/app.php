<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Modules\Auth\Http\Middleware\EnsureNotAgent;
use Modules\Auth\Http\Middleware\MerchantSessionMiddleware;
use Modules\Auth\Http\Middleware\PreventBackHistory;
use Modules\Auth\Http\Middleware\RedirectIfMerchantAuthenticated;
use Modules\Auth\Http\Middleware\RequireReauthentication;
use Symfony\Component\HttpFoundation\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        /**
         * Trust reverse proxies (Cloudflare, Load Balancer, etc.)
         * This allows request()->ip() to return the real client IP
         */
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO
        );

        $middleware->alias([
            'no-cache'        => PreventBackHistory::class,
            'merchant.guest'  => RedirectIfMerchantAuthenticated::class,
            'merchant.auth'   => MerchantSessionMiddleware::class,
            'reauth.required' => RequireReauthentication::class,
            'admin.only'      => EnsureNotAgent::class,
        ]);

        // Internal admin auth (Laravel's built-in guard):
        // - guests: unauthenticated requests go to the admin login, distinct
        //   from the merchant/client portal login above.
        // - users: an already-signed-in admin who opens /admin/login goes to the
        //   admin home. Without this, Laravel falls back to '/', which redirects
        //   to the client portal's /login.
        // Set both in one call: redirectUsersTo() on its own resets the guest
        // redirect to null.
        $middleware->redirectTo(
            guests: fn () => route('admin.login'),
            users: fn () => route('inbound.clients.index'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
