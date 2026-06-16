<?php

namespace Modules\BookSync\Services\Connectors;

use Modules\BookSync\Contracts\AccountingConnectorInterface;

class QuickBooksAccountingConnector implements AccountingConnectorInterface
{
    public function key(): string
    {
        return 'quickbooks';
    }

    public function label(): string
    {
        return 'QuickBooks Online';
    }

    public function description(): string
    {
        return 'Sync transactions as Sales Receipts into QuickBooks Online.';
    }
}
