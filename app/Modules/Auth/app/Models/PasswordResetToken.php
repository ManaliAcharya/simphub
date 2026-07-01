<?php

namespace Modules\Auth\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PasswordResetToken extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $fillable = [
        'client_account_id',
        'code_hash',
        'expires_at',
        'attempts',
        'consumed_at',
        'requested_from_ip',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
