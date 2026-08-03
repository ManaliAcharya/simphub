<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'first_email_sent_at'       => 'datetime',
            'last_reminder_sent_at'     => 'datetime',
            'next_reminder_at'          => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(PaymentSessionReminder::class);
    }
}
