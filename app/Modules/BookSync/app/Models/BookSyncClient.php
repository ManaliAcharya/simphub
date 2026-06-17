<?php

namespace Modules\BookSync\Models;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookSyncClient extends Model implements Authenticatable
{
    use HasUuids, AuthenticatableTrait;

    protected $table = 'booksync_clients';

    protected $guarded = [];

    protected $hidden = ['client_api_key', 'portal_password'];

    protected function casts(): array
    {
        return [
            'client_api_key' => 'encrypted',
        ];
    }

    public function getAuthPasswordName(): string { return 'portal_password'; }

    public function getRememberTokenName(): string { return ''; }

    public function merchants(): HasMany
    {
        return $this->hasMany(BookSyncMerchant::class, 'client_id');
    }
}
