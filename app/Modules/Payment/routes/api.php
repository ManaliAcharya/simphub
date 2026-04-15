<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1/payment')->name('payment.')->group(function (): void {
    // API endpoints can be added here for SPA or hosted-fields integrations.
});
