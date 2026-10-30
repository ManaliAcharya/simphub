<?php

namespace Modules\Inbound\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Inbound\Jobs\PollAdvancedMdChargesJob;
use Modules\Inbound\Jobs\PollLawcusInvoicesJob;
use Modules\Inbound\Jobs\PollWaveInvoicesJob;
use Modules\Inbound\Jobs\RenewClioWebhooksJob;
use Modules\Inbound\Services\Connectors\ClioConnector;
use Modules\Inbound\Services\Connectors\LawcusConnector;
use Modules\Inbound\Services\Connectors\QuickBooksConnector;
use Modules\Inbound\Services\Connectors\WaveConnector;
use Modules\Inbound\Services\Connectors\ZohoConnector;
use Modules\Inbound\Services\Connectors\ZohoCrmConnector;
use Modules\Inbound\Services\PmsConnectorRegistry;
use Nwidart\Modules\Support\ModuleServiceProvider;

class InboundServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Inbound';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'inbound';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(PmsConnectorRegistry::class, function ($app) {
            return new PmsConnectorRegistry([
                $app->make(ClioConnector::class),
                $app->make(ZohoConnector::class),
                $app->make(QuickBooksConnector::class),
                $app->make(LawcusConnector::class),
                $app->make(WaveConnector::class),
                $app->make(ZohoCrmConnector::class),
            ]);
        });
    }

    /**
     * Define module schedules.
     * 
     * @param $schedule
     */
    public function boot(): void
    {
        parent::boot();

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->job(PollAdvancedMdChargesJob::class)->everyFiveMinutes()
                ->withoutOverlapping()
                ->onOneServer();

            $schedule->job(PollWaveInvoicesJob::class)->everyFiveMinutes()
                ->withoutOverlapping()
                ->onOneServer();

            $schedule->job(PollLawcusInvoicesJob::class)->everyFiveMinutes()
                ->withoutOverlapping()
                ->onOneServer();

            // Clio webhooks expire; renewed well ahead so bills and Trust Requests keep arriving.
            $schedule->job(RenewClioWebhooksJob::class)->dailyAt('03:00')
                ->withoutOverlapping()
                ->onOneServer();

            // Monthly subscription links go out on the 1st; runs daily to catch up after downtime.
            $schedule->command('subscriptions:bill')->dailyAt('06:00')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
