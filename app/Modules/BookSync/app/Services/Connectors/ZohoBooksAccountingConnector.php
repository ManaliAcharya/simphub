<?php

namespace Modules\BookSync\Services\Connectors;

use Modules\BookSync\Contracts\AccountingConnectorInterface;

class ZohoBooksAccountingConnector implements AccountingConnectorInterface
{
    public function key(): string
    {
        return 'zoho_books';
    }

    public function label(): string
    {
        return 'Zoho Books';
    }

    public function description(): string
    {
        return 'Sync transactions as Sales invoices into Zoho Books.';
    }
}
