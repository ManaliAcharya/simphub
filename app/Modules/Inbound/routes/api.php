<?php

use Illuminate\Support\Facades\Route;
use Modules\Inbound\Http\Controllers\CrmInvoiceController;
use Modules\Inbound\Http\Controllers\CrmRefundController;
use Modules\Inbound\Http\Controllers\CrmTransactionController;
use Modules\Inbound\Http\Controllers\InvoiceIngestionController;
use Modules\Inbound\Http\Controllers\WebhookController;
use Modules\Inbound\Http\Middleware\CrmApiAuth;

Route::prefix('v1/inbound')->name('inbound.')->group(function () {
    Route::post('/webhooks/{source}', WebhookController::class)->name('webhooks.receive');
    Route::post('/invoices/{source}', [InvoiceIngestionController::class, 'store'])->name('invoices.store');
});

Route::prefix('v1')->name('crm.')->middleware(CrmApiAuth::class)->group(function () {
    Route::post('/invoices',                          [CrmInvoiceController::class, 'store'])->name('invoices.store');
    Route::get('/invoices/{invoice_id}',              [CrmInvoiceController::class, 'show'])->name('invoices.show');
    Route::post('/invoices/{invoice_id}/cancel',      [CrmInvoiceController::class, 'cancel'])->name('invoices.cancel');
    Route::get('/transactions',                       [CrmTransactionController::class, 'index'])->name('transactions.index');
    Route::post('/refunds',                           [CrmRefundController::class, 'store'])->name('refunds.store');
});
