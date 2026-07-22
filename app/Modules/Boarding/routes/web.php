<?php

use Illuminate\Support\Facades\Route;
use Modules\Boarding\Http\Controllers\RedirectController;

Route::middleware('web')->group(function (): void {

    // ── Merchant-facing boarding click/redirect — public ──────────────────────
    Route::get('/sq/{token}', [RedirectController::class, 'click'])->name('boarding.click');

    // Everything else — client creation, login, config page, and the admin
    // client management pages — now lives under Inbound's routes/web.php
    // (/inbound/clients/*, /inbound/boarding), reusing the same URL space
    // and page shells as PMS/Custom clients. Only the underlying data
    // (BoardingClient vs Inbound Client) differs.

});
