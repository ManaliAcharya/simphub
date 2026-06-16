<?php

namespace Modules\BookSync\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookSyncClient extends Model
{
    use HasUuids;

    protected $table = 'booksync_clients';

    protected $guarded = [];

    protected $hidden = ['client_api_key'];

    protected function casts(): array
    {
        return [
            'client_api_key' => 'encrypted',
        ];
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(BookSyncMerchant::class, 'client_id');
    }
}
