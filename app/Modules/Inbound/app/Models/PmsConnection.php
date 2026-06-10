<?php

namespace Modules\Inbound\Models;

use Illuminate\Database\Eloquent\Model;

class PmsConnection extends Model
{
    protected $table = 'pms_connections';

    protected $guarded = [];

    protected $hidden = ['access_token', 'refresh_token', 'webhook_secret'];

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
