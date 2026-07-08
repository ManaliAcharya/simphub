<?php

namespace Modules\Inbound\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Inbound\Jobs\PollAdvancedMdChargesJob;
use Modules\Inbound\Services\Connectors\ClioConnector;
use Modules\Inbound\Services\Connectors\LawcusConnector;
use Modules\Inbound\Services\Connectors\QuickBooksConnector;
use Modules\Inbound\Services\Connectors\WaveConnector;
use Modules\Inbound\Services\Connectors\ZohoConnector;
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
        });
    }
}
