<?php

use Illuminate\Support\Facades\Route;
use Modules\Payment\Http\Controllers\PaymentSessionController;

Route::prefix('v1/payment')->name('payment.')->group(function (): void {
    Route::get('/sessions/{session}',                  [PaymentSessionController::class, 'show'])->name('sessions.show');
    Route::get('/sessions/{session}/paya-form-url',    [PaymentSessionController::class, 'payaFormUrl'])->name('sessions.paya-form-url');
    Route::post('/sessions/{session}/tokenize',        [PaymentSessionController::class, 'tokenize'])->name('sessions.tokenize');
    Route::post('/sessions/{session}/submit',          [PaymentSessionController::class, 'submit'])->name('sessions.submit');
});
