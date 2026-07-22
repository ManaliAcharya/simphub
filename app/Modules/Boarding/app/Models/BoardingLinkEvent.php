<?php

namespace Modules\Boarding\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoardingLinkEvent extends Model
{
    use HasUuids;

    protected $table = 'boarding_link_events';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(BoardingMerchant::class, 'boarding_merchant_id');
    }
}
