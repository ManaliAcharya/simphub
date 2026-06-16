<?php

namespace Modules\BookSync\Providers;

use Modules\BookSync\Services\AccountingConnectorRegistry;
use Modules\BookSync\Services\Connectors\QuickBooksAccountingConnector;
use Modules\BookSync\Services\Connectors\ZohoBooksAccountingConnector;
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

        $this->app->singleton(AccountingConnectorRegistry::class, function () {
            return new AccountingConnectorRegistry([
                new QuickBooksAccountingConnector(),
                new ZohoBooksAccountingConnector(),
            ]);
        });
    }
}
