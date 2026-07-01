<?php

namespace Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;

class AuthAudit extends Model
{

    protected $fillable = [
        'client_account_id',
        'event_type',
        'outcome',
        'failure_reason',
        'ip_address',
        'user_agent',
        'request_id',
        'metadata',

    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
