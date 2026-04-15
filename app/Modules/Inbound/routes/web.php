<?php

use Illuminate\Support\Facades\Route;
use Modules\Inbound\Http\Controllers\ClioAuthController;
use Modules\Inbound\Http\Controllers\ClioIntegrationController;

Route::middleware('web')->group(function (): void {
    Route::prefix('inbound/clio')->name('inbound.clio.')->group(function (): void {
        Route::get('/', [ClioIntegrationController::class, 'show'])->name('page');
        Route::get('/connect', [ClioAuthController::class, 'redirect'])->name('connect');
        Route::get('/callback', [ClioAuthController::class, 'callback'])->name('callback');
    });
});
