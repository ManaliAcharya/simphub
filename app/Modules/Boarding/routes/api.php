<?php

use Illuminate\Support\Facades\Route;
use Modules\Boarding\Http\Controllers\Api\BoardingLinkController;
use Modules\Boarding\Http\Middleware\AuthenticateBoardingClientApiKey;

// ── Client-authenticated API ──────────────────────────────────────────────────
Route::middleware(['api', AuthenticateBoardingClientApiKey::class])
    ->prefix('v1/boarding')
    ->group(function (): void {
        Route::post('boarding-links', [BoardingLinkController::class, 'store']);
        Route::post('boarding-links/revoke', [BoardingLinkController::class, 'revoke']);
    });
