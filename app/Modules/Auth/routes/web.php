<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\ForgotPasswordController;
use Modules\Auth\Http\Controllers\InvitationController;
use Modules\Auth\Http\Controllers\ReauthenticationController;

Route::middleware(['web', 'throttle:global'])->group(function () {

    /* Guest Routes */

    Route::middleware('merchant.guest')->group(function () {

        Route::controller(AuthController::class)->group(function () {

            Route::get('/login', 'index')
                ->name('auth.login');

            Route::post('/login', 'login')
                ->middleware('throttle:login')
                ->name('auth.login.submit');
        });

        Route::controller(InvitationController::class)
            ->prefix('invitation')
            ->name('auth.invitation.')
            ->group(function () {

                Route::get('/accept/{token}', 'show')
                    ->name('accept');

                Route::post('/accept', 'accept')
                    ->name('store');
            });

        Route::controller(ForgotPasswordController::class)
            ->prefix('forgot-password')
            ->name('auth.forgot-password.')
            ->group(function () {

                Route::get('/', 'index')
                    ->name('index');

                Route::post('/request-code', 'requestCode')
                    ->middleware('throttle:forgot-password-request')
                    ->name('request-code');

                Route::post('/verify-code', 'verifyCode')
                    ->middleware('throttle:forgot-password-verify')
                    ->name('verify-code');

                Route::post('/reset', 'resetPassword')
                    ->middleware('throttle:forgot-password-reset')
                    ->name('reset');
            });
    });

    /* Authenticated Routes */

    Route::middleware('merchant.auth')->group(function () {

        Route::post('/logout', [AuthController::class, 'logout'])
            ->name('auth.logout');

        Route::get('/reauthenticate', [ReauthenticationController::class, 'show'])
            ->name('auth.reauthenticate.form');

        Route::post('/reauthenticate', [ReauthenticationController::class, 'verify'])
            ->name('auth.reauthenticate');
    });

    Route::get('/debug-ip', function () {

        return response()->json([
            'ip' => request()->ip(),
            'ips' => request()->ips(),

            // Proxy headers
            'x_forwarded_for' => request()->header('X-Forwarded-For'),
            'x_forwarded_host' => request()->header('X-Forwarded-Host'),
            'x_forwarded_proto' => request()->header('X-Forwarded-Proto'),

            // Cloudflare headers
            'cf_connecting_ip' => request()->header('CF-Connecting-IP'),
            'cf_ip_country' => request()->header('CF-IPCountry'),
            'cf_ray' => request()->header('CF-Ray'),

            // Server side
            'remote_addr' => request()->server('REMOTE_ADDR'),

            // Laravel config check
            'trusted_proxies' => config('trustedproxy.proxies'),

            // Request scheme
            'scheme' => request()->getScheme(),
            'secure' => request()->secure(),

            // User agent
            'user_agent' => request()->userAgent(),
        ]);
    });
});
