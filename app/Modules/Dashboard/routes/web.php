<?php

use Illuminate\Support\Facades\Route;
use Modules\Dashboard\Http\Controllers\AccountSettingsController;
use Modules\Dashboard\Http\Controllers\ActiveSessionController;

Route::middleware([
    'web',
    'merchant.auth',
    'no-cache',
])->group(function (): void {

    Route::prefix('account')
        ->name('account.')
        ->group(function (): void {

            Route::prefix('settings')
                ->name('settings.')
                ->group(function (): void {

                    Route::get('/', [AccountSettingsController::class, 'index'])
                        ->name('index');

                    Route::middleware('reauth.required')
                        ->group(function (): void {

                            Route::post('/email', [AccountSettingsController::class, 'updateEmail'])
                                ->name('email.update');

                            Route::post('/password', [AccountSettingsController::class, 'updatePassword'])
                                ->name('password.update');
                        });
                });

            Route::prefix('sessions')
                ->name('sessions.')
                ->group(function (): void {

                    Route::get('/', [ActiveSessionController::class, 'index'])
                        ->name('index');

                    Route::middleware('reauth.required')
                        ->group(function (): void {

                            Route::post('/logout-others', [ActiveSessionController::class, 'logoutOthers'])
                                ->name('logout-others');

                            Route::post('/{session}/revoke', [ActiveSessionController::class, 'revoke'])
                                ->name('revoke');
                        });
                });
        });
});
