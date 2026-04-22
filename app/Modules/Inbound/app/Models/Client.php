<?php

namespace Modules\Inbound\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'webhook_flow_enabled' => 'boolean',
            'call_api_to_pms' => 'boolean',
            'client_calls_our_api' => 'boolean',
        ];
    }
}
