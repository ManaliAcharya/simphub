<?php

use Illuminate\Support\Facades\Route;
use Modules\Inbound\Http\Controllers\ClientConfigController;
use Modules\Inbound\Http\Controllers\PmsAuthController;
use Modules\Inbound\Http\Controllers\PmsIntegrationController;

Route::middleware('web')->group(function (): void {
    Route::get('/setup/{provider}/{token}', [PmsIntegrationController::class, 'showByToken'])
        ->whereIn('provider', ['clio', 'zoho'])
        ->name('inbound.setup.share');

    Route::prefix('inbound/clients')->name('inbound.clients.')->group(function (): void {
        Route::get('/create', [ClientConfigController::class, 'create'])->name('create');
        Route::post('/', [ClientConfigController::class, 'store'])->name('store');
    });

    foreach (['clio', 'zoho'] as $provider) {
        Route::prefix("inbound/{$provider}")->name("inbound.{$provider}.")->group(function () use ($provider): void {
            Route::get('/', [PmsIntegrationController::class, 'show'])->defaults('provider', $provider)->name('page');
            Route::get('/share/{token}', [PmsIntegrationController::class, 'showByToken'])->defaults('provider', $provider)->name('share');
            Route::get('/connect', [PmsAuthController::class, 'redirect'])->defaults('provider', $provider)->name('connect');
            Route::get('/callback', [PmsAuthController::class, 'callback'])->defaults('provider', $provider)->name('callback');
        });
    }
});
