<?php

namespace Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MerchantSession extends Model
{
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'id',
        'client_account_id',
        'session_token_hash',
        'last_activity_at',
        'absolute_expires_at',
        'ip_address',
        'user_agent',
        'revoked_at',
        'revoked_reason',
        'device_fingerprint',
        'reauthenticated_at',
        'browser',
        'platform',
        'location'
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'last_activity_at' => 'datetime',
        'absolute_expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'reauthenticated_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function clientAccount()
    {
        return $this->belongsTo(
            ClientAccount::class,
            'client_account_id',
            'id'
        );
    }
}
