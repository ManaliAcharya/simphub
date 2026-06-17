<?php

use Illuminate\Support\Facades\Route;
use Modules\BookSync\Http\Controllers\Admin\ApiDocsController;
use Modules\BookSync\Http\Controllers\Admin\ClientController;
use Modules\BookSync\Http\Controllers\Admin\MerchantController;
use Modules\BookSync\Http\Controllers\Setup\MerchantSetupController;
use Modules\BookSync\Http\Controllers\Docs\IntegrationGuideController;

Route::middleware('web')->group(function (): void {

    // ── Public integration guide ──────────────────────────────────────────────
    Route::get('/booksync/docs', [IntegrationGuideController::class, 'show'])->name('booksync.docs');

    // ── Admin — Client management ─────────────────────────────────────────────
    Route::prefix('booksync/admin/clients')->name('booksync.admin.clients.')->group(function (): void {
        Route::get('/', [ClientController::class, 'index'])->name('index');
        Route::get('/create', [ClientController::class, 'create'])->name('create');
        Route::post('/', [ClientController::class, 'store'])->name('store');
        Route::get('/{clientId}', [ClientController::class, 'show'])->name('show');
        Route::get('/{clientId}/api-docs', [ApiDocsController::class, 'show'])->name('api-docs');
        Route::post('/{clientId}/toggle-status', [ClientController::class, 'toggleStatus'])->name('toggle-status');
    });

    // ── Admin — Merchant management ───────────────────────────────────────────
    Route::prefix('booksync/admin/clients/{clientId}/merchants')->name('booksync.admin.merchants.')->group(function (): void {
        Route::get('/create', [MerchantController::class, 'create'])->name('create');
        Route::post('/', [MerchantController::class, 'store'])->name('store');
    });

    Route::prefix('booksync/admin/merchants')->name('booksync.admin.merchants.')->group(function (): void {
        Route::get('/{merchantId}', [MerchantController::class, 'show'])->name('show');
        Route::post('/{merchantId}/toggle-status', [MerchantController::class, 'toggleStatus'])->name('toggle-status');
    });

    // ── Merchant setup flow ───────────────────────────────────────────────────
    Route::prefix('booksync/setup')->name('booksync.setup.')->group(function (): void {
        Route::get('/{setupToken}', [MerchantSetupController::class, 'show'])->name('show');
        Route::get('/{setupToken}/connect', [MerchantSetupController::class, 'redirect'])->name('redirect');
        // Fixed QB OAuth callback URL — merchant identified via encrypted `state` parameter
        Route::get('/qb/callback', [MerchantSetupController::class, 'callback'])->name('callback');
        Route::post('/{setupToken}/account', [MerchantSetupController::class, 'saveAccount'])->name('save-account');
        Route::get('/{setupToken}/complete', [MerchantSetupController::class, 'complete'])->name('complete');
        Route::get('/{setupToken}/edit', [MerchantSetupController::class, 'edit'])->name('edit');
        Route::get('/{setupToken}/refresh', [MerchantSetupController::class, 'refresh'])->name('refresh');
    });

});
