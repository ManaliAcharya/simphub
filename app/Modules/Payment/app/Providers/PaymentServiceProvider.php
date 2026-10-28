<?php

namespace Modules\Payment\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Payment\Jobs\PollAchSettlementsJob;

class PaymentServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Payment';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'payment';

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

    /**
     * Define module schedules.
     *
     * @param $schedule
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command('reminders:dispatch')
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer();

        // ACH settles/returns in Paya's overnight run, so check once a day in the morning.
        $schedule->job(new PollAchSettlementsJob)
            ->dailyAt('07:00')
            ->onOneServer();
    }
}
