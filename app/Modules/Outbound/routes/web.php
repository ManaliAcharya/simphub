<?php

use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    // Outbound web routes can be added here if a gateway requires a browser handoff.
});
