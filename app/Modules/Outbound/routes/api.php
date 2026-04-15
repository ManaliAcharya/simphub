<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1/outbound')->name('outbound.')->group(function (): void {
    // Gateway configuration or tokenization endpoints can live here.
});
