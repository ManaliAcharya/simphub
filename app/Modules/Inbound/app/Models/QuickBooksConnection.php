<?php

namespace Modules\Inbound\Models;

class QuickBooksConnection extends PmsConnection
{
    public function realmId(): string
    {
        return (string) data_get($this->meta, 'realm_id', '');
    }

    public function environment(): string
    {
        return (string) data_get($this->meta, 'environment', 'sandbox');
    }

    public function companyName(): string
    {
        return (string) data_get($this->meta, 'company_name', '');
    }
}
