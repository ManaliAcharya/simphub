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

    protected $hidden = ['qb_access_token', 'qb_refresh_token', 'signing_secret'];

    protected function casts(): array
    {
        return [
            'qb_access_token'             => 'encrypted',
            'qb_refresh_token'            => 'encrypted',
            'signing_secret'              => 'encrypted',
            'previous_signing_secret'     => 'encrypted',
            'qb_token_expires_at'         => 'datetime',
            'qb_connected_at'             => 'datetime',
            'previous_secret_expires_at'  => 'datetime',
            'surcharge_enabled'           => 'boolean',
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

        return rtrim(config('app.url'), '/') . '/booksync/sale/' . $this->posting_token;
    }

    public function setupLink(): string
    {
        return rtrim(config('app.url'), '/') . '/booksync/setup/' . $this->setup_token;
    }
}
