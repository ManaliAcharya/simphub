<?php

namespace Modules\Inbound\Models;

use Illuminate\Database\Eloquent\Model;

class ClioConnection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'token_expires_at' => 'datetime',
            'webhook_expires_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
