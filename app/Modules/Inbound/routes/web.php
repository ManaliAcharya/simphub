<?php

use Illuminate\Support\Facades\Route;
use Modules\Boarding\Http\Controllers\Admin\ClientController as BoardingAdminClientController;
use Modules\Boarding\Http\Controllers\Portal\ClientPortalController as BoardingClientPortalController;
use Modules\Inbound\Http\Controllers\AdvancedMdIntegrationController;
use Modules\Inbound\Http\Controllers\ClientConfigController;
use Modules\Inbound\Http\Controllers\MindbodyController;
use Modules\Inbound\Http\Controllers\PmsAuthController;
use Modules\Inbound\Http\Controllers\PmsFeatureSettingsController;
use Modules\Inbound\Http\Controllers\PmsIntegrationController;
use Modules\Inbound\Http\Controllers\QuickBooksAuthController;
use Modules\Inbound\Http\Controllers\CustomPmsController;
use Modules\Inbound\Http\Controllers\TerminalClientController;

Route::middleware('web')->group(function (): void {

    // ── Admin settings ────────────────────────────────────────────────────
    Route::prefix('inbound/settings')->name('inbound.settings.')->group(function (): void {
        Route::get('/pms-features', [PmsFeatureSettingsController::class, 'show'])->name('pms-features');
        Route::post('/pms-features', [PmsFeatureSettingsController::class, 'update'])->name('pms-features.update');
    });

    // ── Public / admin routes (no merchant login required) ────────────────
    Route::get('/setup/{provider}/{token}', [PmsIntegrationController::class, 'showByToken'])
        ->whereIn('provider', ['clio', 'zoho', 'lawcus', 'wave', 'quickbooks', 'mindbody'])
        ->middleware(['merchant.auth', 'no-cache'])
        ->name('inbound.setup.share');

    Route::prefix('inbound/clients')->name('inbound.clients.')->group(function (): void {
        Route::get('/', [ClientConfigController::class, 'index'])->name('index');
        Route::get('/create', [ClientConfigController::class, 'create'])->name('create');
        Route::post('/', [ClientConfigController::class, 'store'])->name('store');
        Route::get('/created', [ClientConfigController::class, 'created'])->name('created');

        //New routes for delete &  inactivate client
        Route::patch('/{client_id}',[ClientConfigController::class,'updateStatus'])->name('update-status');
        Route::delete('/{client_id}',[ClientConfigController::class,'destroy'])->name('destroy');

        // ── Boarding (ISO) client admin pages — additional to the shared flow above ──
        Route::prefix('boarding')->name('boarding.')->group(function (): void {
            Route::get('/{clientId}', [BoardingAdminClientController::class, 'show'])->name('show');
            Route::get('/{clientId}/api-docs', [BoardingAdminClientController::class, 'apiDocs'])->name('api-docs');
            Route::post('/{clientId}/toggle-status', [BoardingAdminClientController::class, 'toggleStatus'])->name('toggle-status');
            Route::post('/{clientId}/resend-invitation', [BoardingAdminClientController::class, 'resendInvitation'])->name('resend-invitation');
        });

        Route::middleware(['merchant.auth', 'no-cache'])->group(function (): void {

            Route::post('/{pms_client_id}/gateways', [ClientConfigController::class, 'updateGateways'])->name('update-gateways');
            Route::post('/{pms_client_id}/gateway-credentials', [ClientConfigController::class, 'updateGatewayCredentials'])->name('update-gateway-credentials');
            Route::post('/{pms_client_id}/gateways/pause', [ClientConfigController::class, 'toggleGatewayPause'])->name('toggle-gateway-pause');
            Route::post('/{pms_client_id}/webhook-url', [ClientConfigController::class, 'updateWebhookUrl'])->name('update-webhook-url');
            Route::post('/{pms_client_id}/fees', [ClientConfigController::class, 'updateFees'])->name('update-fees');
            Route::post('/{pms_client_id}/qb-settings', [ClientConfigController::class, 'updateQbSettings'])->name('update-qb-settings');
            Route::post('/{pms_client_id}/mid-routes', [ClientConfigController::class, 'saveMidRoutes'])->name('save-mid-routes');
            Route::post('/{pms_client_id}/logo', [ClientConfigController::class, 'uploadLogo'])->name('upload-logo');
            Route::delete('/{pms_client_id}/logo', [ClientConfigController::class, 'removeLogo'])->name('remove-logo');
            Route::post('/{pms_client_id}/notification-settings', [ClientConfigController::class, 'updateNotificationSettings'])->name('update-notification-settings');
            Route::post('/{pms_client_id}/email-config', [ClientConfigController::class, 'updateEmailConfig'])->name('update-email-config');
            Route::get('/terminal-created', [TerminalClientController::class, 'created'])->name('terminal-created');
            Route::get('/api-docs', [CustomPmsController::class, 'apiDocs'])->name('api-docs');
            Route::get('/{pms_client_id}/{action}', [ClientConfigController::class, 'redirectAction'])
                ->where('action', 'email-config|fees|gateways|gateway-credentials|webhook-url|notification-settings|qb-settings|mid-routes|logo')
                ->name('redirect-action');
            Route::post('/{pms_client_id}/invoices/{invoice}/resend', [ClientConfigController::class, 'resendInvoice'])->name('resend-invoice');

        });
    });

    Route::middleware(['merchant.auth', 'no-cache'])->group(function (): void {

        Route::post('/inbound/zoho/default-account', [PmsIntegrationController::class, 'saveZohoDefaultAccount'])
            ->name('inbound.zoho.default-account');

        Route::post('/inbound/clio/default-bank-account', [PmsIntegrationController::class, 'saveClioDefaultBankAccount'])
            ->name('inbound.clio.default-bank-account');

        Route::post('/inbound/quickbooks/default-account', [PmsIntegrationController::class, 'saveQbDefaultAccount'])
            ->name('inbound.quickbooks.default-account');

        Route::post('/inbound/quickbooks/surcharge-account', [PmsIntegrationController::class, 'saveQbSurchargeAccount'])
            ->name('inbound.quickbooks.surcharge-account');

        Route::post('/inbound/quickbooks/auto-resend-toggle', [PmsIntegrationController::class, 'saveQbAutoResendToggle'])
            ->name('inbound.quickbooks.auto-resend-toggle');

        Route::post('/inbound/quickbooks/surcharge-toggle', [PmsIntegrationController::class, 'saveQbSurchargeToggle'])
            ->name('inbound.quickbooks.surcharge-toggle');

        Route::post('/inbound/lawcus/default-bank-account', [PmsIntegrationController::class, 'saveLawcusDefaultBankAccount'])
            ->name('inbound.lawcus.default-bank-account');

        Route::post('/inbound/wave/default-account', [PmsIntegrationController::class, 'saveWaveDefaultAccount'])
            ->name('inbound.wave.default-account');
    });

    // OAuth callbacks — initiated by external provider, no session cookie present
    foreach (['clio', 'zoho', 'lawcus', 'wave'] as $provider) {

        Route::prefix("inbound/{$provider}")
            ->name("inbound.{$provider}.")
            ->middleware(['merchant.auth', 'no-cache'])
            ->group(function () use ($provider): void {

                Route::get('/', [PmsIntegrationController::class, 'show'])
                    ->defaults('provider', $provider)
                    ->name('page');

                Route::get('/connect', [PmsAuthController::class, 'redirect'])
                    ->defaults('provider', $provider)
                    ->name('connect');
            });

        Route::prefix("inbound/{$provider}")
            ->name("inbound.{$provider}.")
            ->group(function () use ($provider): void {

                Route::get('/share/{token}', [PmsIntegrationController::class, 'showByToken'])
                    ->defaults('provider', $provider)
                    ->name('share');

                Route::get('/callback', [PmsAuthController::class, 'callback'])
                    ->defaults('provider', $provider)
                    ->name('callback');
            });
    }

    Route::prefix('inbound/mindbody')->name('inbound.mindbody.')->group(function (): void {
        Route::get('/', [MindbodyController::class, 'show'])->name('page');
        Route::post('/connect', [MindbodyController::class, 'connect'])->name('connect');
        Route::post('/disconnect', [MindbodyController::class, 'disconnect'])->name('disconnect');
        Route::post('/settings', [MindbodyController::class, 'saveSettings'])->name('settings');
    });

    Route::prefix('inbound/advancedmd')->name('inbound.advancedmd.')->middleware(['merchant.auth', 'no-cache'])->group(function (): void {
        Route::get('/', [AdvancedMdIntegrationController::class, 'show'])->name('page');
        Route::post('/connect', [AdvancedMdIntegrationController::class, 'connect'])->name('connect');
        Route::post('/disconnect', [AdvancedMdIntegrationController::class, 'disconnect'])->name('disconnect');
    });

    // ── Boarding (ISO) clients — same URL space/shell as PMS clients, separate DB ──
    Route::prefix('inbound/boarding')->name('inbound.boarding.')->middleware(['merchant.auth', 'no-cache'])->group(function (): void {
        Route::get('/', [BoardingClientPortalController::class, 'show'])->name('page');
        Route::post('/master-links', [BoardingClientPortalController::class, 'updateMasterLinks'])->name('master-links');
        Route::post('/webhook-url', [BoardingClientPortalController::class, 'updateWebhookUrl'])->name('webhook-url');
    });


    Route::prefix('inbound/quickbooks')
        ->name('inbound.quickbooks.')
        ->group(function (): void {

            // Merchant-only pages
            Route::middleware(['merchant.auth', 'no-cache'])->group(function (): void {
                Route::get('/', [PmsIntegrationController::class, 'show'])
                    ->defaults('provider', 'quickbooks')
                    ->name('page');

                Route::get('/connect', [PmsAuthController::class, 'redirect'])
                    ->defaults('provider', 'quickbooks')
                    ->name('connect');

                Route::get('/select-company', [QuickBooksAuthController::class, 'selectCompany'])
                    ->name('select-company');

                Route::post('/confirm-company', [QuickBooksAuthController::class, 'confirmCompany'])
                    ->name('confirm-company');

                Route::post('/disconnect', [QuickBooksAuthController::class, 'disconnect'])->name('disconnect');

                Route::post('/refresh-company-name', [PmsIntegrationController::class, 'refreshQbCompanyName'])
                    ->name('refresh-company-name');
            });

            // Public routes
            Route::get('/share/{token}', [PmsIntegrationController::class, 'showByToken'])
                ->defaults('provider', 'quickbooks')
                ->name('share');

            Route::get('/callback', [QuickBooksAuthController::class, 'callback'])
                ->name('callback');
        });
});
