<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AdminAuthController;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\ForgotPasswordController;
use Modules\Auth\Http\Controllers\InvitationController;
use Modules\Auth\Http\Controllers\ReauthenticationController;

Route::middleware(['web', 'throttle:global'])->group(function () {

    /* Admin Routes (internal staff — separate from the client/merchant portal above) */

    Route::prefix('admin')->name('admin.')->group(function () {

        Route::middleware('guest')->group(function () {

            Route::get('/login', [AdminAuthController::class, 'index'])
                ->name('login');

            Route::post('/login', [AdminAuthController::class, 'login'])
                ->middleware('throttle:login')
                ->name('login.submit');
        });

        Route::middleware('auth')->group(function () {

            Route::post('/logout', [AdminAuthController::class, 'logout'])
                ->name('logout');
        });
    });

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
});
