<?php

namespace Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class InvitationToken extends Model
{
    protected $table = 'invitation_tokens';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'client_account_id',
        'token_hash',
        'expires_at',
        'consumed_at',
        'created_by_admin_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (InvitationToken $token) {
            if (empty($token->id)) {
                $token->id = (string) Str::uuid();
            }
        });
    }

    public function clientAccount()
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }
}
