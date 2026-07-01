<?php

namespace Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Inbound\Models\Client;

class ClientAccount extends Model
{
    protected $table = 'client_accounts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'client_id',
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

    public function client()
    {
        return $this->belongsTo(
            Client::class,
            'client_id',
            'id'
        );
    }
}
