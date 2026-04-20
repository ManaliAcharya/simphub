<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'recipient_emails' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
