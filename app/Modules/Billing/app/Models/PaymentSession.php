<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
            'reminders_paused_at'       => 'datetime',
            'reminder_cycle_started_at' => 'datetime',
            'reminder_cycle_offset'     => 'integer',
        ];
    }

    /** When the current reminder schedule started: the first email, or the last Resume. */
    public function reminderCycleStartedAt(): ?Carbon
    {
        return $this->reminder_cycle_started_at ?? $this->first_email_sent_at;
    }

    /** Which step of the reminder schedule comes next. */
    public function reminderStepIndex(): int
    {
        return (int) $this->reminders_sent_count - (int) $this->reminder_cycle_offset;
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
