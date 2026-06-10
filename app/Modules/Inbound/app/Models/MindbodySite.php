<?php

namespace Modules\Inbound\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MindbodySite extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = [
        'staff_username_encrypted',
        'staff_password_encrypted',
        'webhook_signature_key_encrypted',
        'staff_token',
    ];

    protected function casts(): array
    {
        return [
            'staff_username_encrypted'        => 'encrypted',
            'staff_password_encrypted'        => 'encrypted',
            'webhook_signature_key_encrypted' => 'encrypted',
            'staff_token'                     => 'encrypted',
            'webhook_active'                  => 'boolean',
            'is_active'                       => 'boolean',
            'mb_payment_posted'               => 'boolean',
            'staff_token_expires_at'          => 'datetime',
        ];
    }

    public function saleLinks(): HasMany
    {
        return $this->hasMany(MindbodySaleLink::class, 'mindbody_site_id');
    }

    public function staffUsername(): string
    {
        return (string) ($this->staff_username_encrypted ?? '');
    }

    public function staffPassword(): string
    {
        return (string) ($this->staff_password_encrypted ?? '');
    }

    public function signatureKey(): string
    {
        return (string) ($this->webhook_signature_key_encrypted ?? '');
    }

    public function isTokenExpired(): bool
    {
        try {
            $token = $this->staff_token;
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            // Plaintext token from before encryption was added — treat as expired
            return true;
        }

        if (! $token || ! $this->staff_token_expires_at) {
            return true;
        }

        return now()->gte($this->staff_token_expires_at->subMinutes(5));
    }
}
