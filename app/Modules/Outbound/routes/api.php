<?php

use Illuminate\Support\Facades\Route;
use Modules\Outbound\Http\Controllers\DirectPaymentController;
use Modules\Outbound\Http\Controllers\PayaTokenizeController;
use Modules\Outbound\Http\Controllers\TerminalPaymentController;

Route::prefix('v1/outbound')->name('outbound.')->group(function (): void {
    // Gateway configuration or tokenization endpoints can live here.
});

Route::prefix('v1/payment')->name('payment.direct.')->group(function (): void {
    Route::post('/direct/{merchantId}/tokenize', [PayaTokenizeController::class, 'tokenize'])->name('tokenize');
    Route::post('/direct/{merchantId}/charge',   [DirectPaymentController::class,   'charge'])->name('charge');
});

Route::prefix('v1/terminal')->name('terminal.')->group(function (): void {
    Route::post('/{merchantId}/charge', [TerminalPaymentController::class, 'charge'])->name('charge');
});
