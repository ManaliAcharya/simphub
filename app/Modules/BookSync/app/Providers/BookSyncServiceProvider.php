<?php

namespace Modules\BookSync\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class BookSyncServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'BookSync';

    protected string $nameLower = 'booksync';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();
    }
}
