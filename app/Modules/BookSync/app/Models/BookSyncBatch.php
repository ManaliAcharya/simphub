<?php

namespace Modules\BookSync\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookSyncBatch extends Model
{
    use HasUuids;

    protected $table = 'booksync_batches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'batch_date' => 'date',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(BookSyncMerchant::class, 'merchant_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BookSyncTransaction::class, 'batch_id');
    }

    public function recalculateCounts(): void
    {
        $counts = $this->transactions()
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        $posted  = (int) ($counts['posted'] ?? 0);
        $skipped = (int) ($counts['already_posted'] ?? 0);
        $failed  = (int) ($counts['failed'] ?? 0);
        $queued  = (int) ($counts['queued'] ?? 0);

        $total   = $posted + $skipped + $failed + $queued;
        $status  = $queued > 0 ? 'processing' : ($failed > 0 ? 'partial' : 'completed');

        $this->update(compact('posted', 'skipped', 'failed', 'queued', 'total', 'status'));
    }
}
