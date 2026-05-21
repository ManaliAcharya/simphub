<?php

namespace Modules\Inbound\Providers;

use Modules\Inbound\Services\Connectors\ClioConnector;
use Modules\Inbound\Services\Connectors\LawcusConnector;
use Modules\Inbound\Services\Connectors\QuickBooksConnector;
use Modules\Inbound\Services\Connectors\WaveConnector;
use Modules\Inbound\Services\Connectors\ZohoConnector;
use Modules\Inbound\Services\PmsConnectorRegistry;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

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
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
