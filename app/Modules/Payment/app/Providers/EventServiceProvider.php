<?php

namespace Modules\Payment\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Payment\Events\PaymentApproved;
use Modules\Payment\Listeners\SendPaymentConfirmationListener;
use Modules\Payment\Listeners\SyncInvoicePaidListener;
use Modules\Payment\Listeners\TrustAccountingListener;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        PaymentApproved::class => [
            SyncInvoicePaidListener::class,
            TrustAccountingListener::class,
            SendPaymentConfirmationListener::class,
        ],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
