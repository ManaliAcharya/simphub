<?php

namespace Modules\Boarding\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardingMerchant extends Model
{
    use HasUuids;

    protected $table = 'boarding_merchants';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'clicked_at'    => 'datetime',
            'revoked_at'    => 'datetime',
            'merchant_info' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(BoardingClient::class, 'client_id');
    }

    public function linkEvents(): HasMany
    {
        return $this->hasMany(BoardingLinkEvent::class, 'boarding_merchant_id');
    }

    public function boardingLink(): string
    {
        return rtrim(config('app.url'), '/') . '/sq/' . $this->token;
    }
}
