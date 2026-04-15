<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1/audit')->name('audit.')->group(function (): void {
    // Audit API endpoints will be registered here.
});
