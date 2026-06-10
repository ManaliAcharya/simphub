<?php

namespace Modules\Inbound\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MindbodySaleLink extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'mb_payment_posted'     => 'boolean',
            'mb_payment_posted_at'  => 'datetime',
            'payment_link_sent_at'  => 'datetime',
            'raw_sale_payload'      => 'array',
            'fee_percent'           => 'decimal:2',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(MindbodySite::class, 'mindbody_site_id');
    }

    public function isPending(): bool
    {
        return $this->payment_status === 'pending';
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }
}
