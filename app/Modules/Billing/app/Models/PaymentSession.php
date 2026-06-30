<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentSession extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at'                => 'datetime',
            'completed_at'              => 'datetime',
            'payment_link_sent_at'      => 'datetime',
            'last_email_sent_at'        => 'datetime',
            'payment_link_last_sent_to' => 'array',
            'original_amount'           => 'decimal:2',
            'link_status'               => 'string',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
