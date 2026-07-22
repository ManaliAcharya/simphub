<?php

namespace Modules\Boarding\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class BoardingServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Boarding';

    protected string $nameLower = 'boarding';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];
}
