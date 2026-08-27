<?php

namespace Modules\Auth\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Auth\Support\RateLimiting\AuthRateLimiters;
use Modules\Auth\Console\SendClientInvitations;
use Modules\Auth\Console\CreateClientAccounts;
use Modules\Auth\Console\CreateBoardingClientAccounts;
use Modules\Auth\Console\CreateAdminUser;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AuthServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Auth';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'auth';

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();
        AuthRateLimiters::register();

        $this->commands([
            CreateClientAccounts::class,
            SendClientInvitations::class,
            CreateBoardingClientAccounts::class,
            CreateAdminUser::class,
        ]);
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
