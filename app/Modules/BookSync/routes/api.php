<?php

use Illuminate\Support\Facades\Route;
use Modules\BookSync\Http\Controllers\Api\BatchController;
use Modules\BookSync\Http\Controllers\Api\MerchantApiController;
use Modules\BookSync\Http\Middleware\AuthenticateClientApiKey;

// ── Client-authenticated API ──────────────────────────────────────────────────
Route::middleware(['api', AuthenticateClientApiKey::class])->group(function (): void {

    // Merchant management
    Route::get('booksync/api/v1/merchants', [MerchantApiController::class, 'index']);
    Route::post('booksync/api/v1/merchants', [MerchantApiController::class, 'store']);
    Route::get('booksync/api/v1/merchants/{merchantId}', [MerchantApiController::class, 'show']);
    Route::post('booksync/api/v1/merchants/{merchantId}/rotate-secret', [MerchantApiController::class, 'rotateSecret']);

    // Batch status check
    Route::get('booksync/api/v1/batches/{batchId}', [BatchController::class, 'show']);

    // Transaction posting — uses merchant posting_token, not merchant_id
    Route::post('booksync/post/{merchantToken}', [BatchController::class, 'post']);
});
