<?php

use Illuminate\Support\Facades\Route;
use Modules\Inbound\Http\Controllers\WebhookController;

Route::prefix('v1/inbound')->name('inbound.')->group(function () {
    Route::post('/webhooks/{source}', WebhookController::class)->name('webhooks.receive');
});
