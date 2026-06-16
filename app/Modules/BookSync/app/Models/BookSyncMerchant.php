<?php

namespace Modules\BookSync\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookSyncMerchant extends Model
{
    use HasUuids;

    protected $table = 'booksync_merchants';

    protected $guarded = [];

    protected $hidden = ['qb_access_token', 'qb_refresh_token'];

    protected function casts(): array
    {
        return [
            'qb_access_token'    => 'encrypted',
            'qb_refresh_token'   => 'encrypted',
            'qb_token_expires_at' => 'datetime',
            'qb_connected_at'    => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(BookSyncClient::class, 'client_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(BookSyncBatch::class, 'merchant_id');
    }

    public function isQbConnected(): bool
    {
        return $this->status === 'active' && $this->qb_realm_id !== null;
    }

    public function postingUrl(): ?string
    {
        if (! $this->posting_token) {
            return null;
        }

        return rtrim(config('app.url'), '/') . '/booksync/post/' . $this->posting_token;
    }

    public function setupLink(): string
    {
        return rtrim(config('app.url'), '/') . '/booksync/setup/' . $this->setup_token;
    }
}
