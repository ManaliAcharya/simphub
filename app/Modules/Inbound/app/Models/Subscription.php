<?php

namespace Modules\Inbound\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_ACTIVE          = 'active';
    public const STATUS_CANCELLED       = 'cancelled';

    public const CYCLE_MONTHLY = 'monthly';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_cents'    => 'integer',
            'next_billing_on' => 'date',
            'last_billed_at'  => 'datetime',
            'activated_at'    => 'datetime',
            'cancelled_at'    => 'datetime',
            'effective_date'  => 'date',
        ];
    }

    /** Subscriptions that still bill: awaiting first payment or active. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING_PAYMENT, self::STATUS_ACTIVE]);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function amountDecimal(): float
    {
        return round($this->amount_cents / 100, 2);
    }
}
