<?php

namespace Modules\BookSync\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookSyncTransaction extends Model
{
    use HasUuids;

    protected $table = 'booksync_transactions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'next_retry_at'    => 'datetime',
            'posted_at'        => 'datetime',
            'amount'           => 'decimal:2',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BookSyncBatch::class, 'batch_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(BookSyncMerchant::class, 'merchant_id');
    }

    /**
     * Delay before attempt N (seconds).
     * Attempt 1 is immediate; this returns the delay before attempts 2–7.
     * Attempt: 2=30s, 3=2m, 4=10m, 5=1h, 6=6h, 7=24h
     */
    public static function retryDelaySeconds(int $attempt): int
    {
        return match ($attempt) {
            2 => 30,
            3 => 120,
            4 => 600,
            5 => 3600,
            6 => 21600,
            default => 86400,
        };
    }

    public function hasExhaustedRetries(): bool
    {
        return $this->retry_count >= 7;
    }
}
