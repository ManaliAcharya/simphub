<?php

use Illuminate\Support\Facades\Route;
use Modules\Payment\Http\Controllers\PaymentPageController;
use Modules\Payment\Http\Controllers\PaymentSubmitController;

Route::middleware('web')->prefix('pay')->name('payment.')->group(function (): void {
    Route::get('/{session}', [PaymentPageController::class, 'show'])->name('page.show');
    Route::post('/{session}', [PaymentSubmitController::class, 'store'])->name('submit.store');
});
