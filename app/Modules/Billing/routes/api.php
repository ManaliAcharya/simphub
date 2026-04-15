<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1/billing')->name('billing.')->group(function (): void {
    // Billing API endpoints will be registered here.
});
