<?php

namespace Modules\Inbound\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AdvancedMdPractice extends Model
{
    use HasUuids;

    protected $table = 'advancedmd_practices';

    protected $guarded = [];

    protected $hidden = [
        'username_encrypted',
        'password_encrypted',
        'session_token',
    ];

    protected function casts(): array
    {
        return [
            'username_encrypted' => 'encrypted',
            'password_encrypted' => 'encrypted',
            'session_token'      => 'encrypted',
            'session_expires_at' => 'datetime',
            'last_polled_at'     => 'datetime',
            'is_active'          => 'boolean',
            'consecutive_poll_failures' => 'integer',
        ];
    }

    public function username(): string
    {
        return (string) ($this->username_encrypted ?? '');
    }

    public function password(): string
    {
        return (string) ($this->password_encrypted ?? '');
    }

    public function isSessionExpired(): bool
    {
        try {
            $token = $this->session_token;
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return true;
        }

        if (! $token || ! $this->session_expires_at) {
            return true;
        }

        // Treat as expired 5 minutes early to avoid mid-request expiry
        return now()->gte($this->session_expires_at->subMinutes(5));
    }
}
