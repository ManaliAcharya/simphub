<?php

use Illuminate\Support\Facades\Route;
use Modules\Inbound\Http\Controllers\ClientConfigController;
use Modules\Inbound\Http\Controllers\PmsAuthController;
use Modules\Inbound\Http\Controllers\PmsIntegrationController;
use Modules\Inbound\Http\Controllers\QuickBooksAuthController;

Route::middleware('web')->group(function (): void {
    Route::get('/setup/{provider}/{token}', [PmsIntegrationController::class, 'showByToken'])
        ->whereIn('provider', ['clio', 'zoho', 'lawcus'])
        ->name('inbound.setup.share');

    Route::prefix('inbound/clients')->name('inbound.clients.')->group(function (): void {
        Route::get('/create', [ClientConfigController::class, 'create'])->name('create');
        Route::post('/', [ClientConfigController::class, 'store'])->name('store');
    });

    Route::post('/inbound/zoho/default-account', [PmsIntegrationController::class, 'saveZohoDefaultAccount'])
        ->name('inbound.zoho.default-account');

    Route::post('/inbound/clio/default-bank-account', [PmsIntegrationController::class, 'saveClioDefaultBankAccount'])
        ->name('inbound.clio.default-bank-account');

    Route::post('/inbound/quickbooks/default-account', [PmsIntegrationController::class, 'saveQbDefaultAccount'])
        ->name('inbound.quickbooks.default-account');

    Route::post('/inbound/lawcus/default-bank-account', [PmsIntegrationController::class, 'saveLawcusDefaultBankAccount'])
        ->name('inbound.lawcus.default-bank-account');

    foreach (['clio', 'zoho', 'lawcus'] as $provider) {
        Route::prefix("inbound/{$provider}")->name("inbound.{$provider}.")->group(function () use ($provider): void {
            Route::get('/', [PmsIntegrationController::class, 'show'])->defaults('provider', $provider)->name('page');
            Route::get('/share/{token}', [PmsIntegrationController::class, 'showByToken'])->defaults('provider', $provider)->name('share');
            Route::get('/connect', [PmsAuthController::class, 'redirect'])->defaults('provider', $provider)->name('connect');
            Route::get('/callback', [PmsAuthController::class, 'callback'])->defaults('provider', $provider)->name('callback');
        });
    }

    Route::prefix('inbound/quickbooks')->name('inbound.quickbooks.')->group(function (): void {
        Route::get('/', [PmsIntegrationController::class, 'show'])->defaults('provider', 'quickbooks')->name('page');
        Route::get('/share/{token}', [PmsIntegrationController::class, 'showByToken'])->defaults('provider', 'quickbooks')->name('share');
        Route::get('/connect', [PmsAuthController::class, 'redirect'])->defaults('provider', 'quickbooks')->name('connect');
        Route::get('/callback', [QuickBooksAuthController::class, 'callback'])->name('callback');
        Route::get('/select-company', [QuickBooksAuthController::class, 'selectCompany'])->name('select-company');
        Route::post('/confirm-company', [QuickBooksAuthController::class, 'confirmCompany'])->name('confirm-company');
    });
});
