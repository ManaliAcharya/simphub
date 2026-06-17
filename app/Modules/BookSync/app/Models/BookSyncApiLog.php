<?php

namespace Modules\BookSync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookSyncApiLog extends Model
{
    protected $table = 'booksync_api_logs';

    public $timestamps  = false;   // audit log rows are immutable
    public $incrementing = true;
    protected $keyType  = 'int';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(BookSyncClient::class, 'client_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(BookSyncMerchant::class, 'merchant_id');
    }
}
