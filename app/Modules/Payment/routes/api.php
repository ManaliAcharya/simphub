<?php

use Illuminate\Support\Facades\Route;
use Modules\Payment\Http\Controllers\PaymentSessionController;

Route::prefix('v1/payment')->name('payment.')->group(function (): void {
    Route::get('/sessions/{session}', [PaymentSessionController::class, 'show'])->name('sessions.show');
    Route::post('/sessions/{session}/submit', [PaymentSessionController::class, 'submit'])->name('sessions.submit');
});
