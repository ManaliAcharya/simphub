<?php

namespace Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;

class LoginAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'email_lower',
        'ip_address',
        'outcome',
        'attempted_at',
    ];

    protected $casts = [
        'attempted_at' => 'datetime',
    ];
}
