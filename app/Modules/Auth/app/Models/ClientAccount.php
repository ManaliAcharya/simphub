<?php

namespace Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ClientAccount extends Model
{
    protected $table = 'client_accounts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'owner_type',
        'owner_id',
        'email',
        'email_lower',
        'password_hash',
        'password_set_at',
        'is_active',
        'is_suspended',
        'suspended_reason',
        'suspended_at',
        'last_login_at',
        'last_login_ip',
        'last_login_ua',
    ];

    protected $casts = [
        'password_set_at' => 'datetime',
        'suspended_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
        'is_suspended' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (ClientAccount $account) {
            if (empty($account->id)) {
                $account->id = (string) Str::uuid();
            }
        });
    }

    public function owner()
    {
        return $this->morphTo();
    }
}
