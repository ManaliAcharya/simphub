<?php

namespace Modules\BookSync\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookSyncRefund extends Model
{
    use HasUuids;

    protected $table = 'booksync_refunds';

    protected $fillable = [
        'merchant_id',
        'reference',
        'original_reference',
        'customer_name',
        'customer_email',
        'amount',
        'payment_method',
        'transaction_date',
        'memo',
        'status',
        'qb_refundreceipt_id',
        'qb_customer_id',
        'error_message',
        'posted_at',
    ];

    protected $casts = [
        'amount'           => 'float',
        'transaction_date' => 'date',
        'posted_at'        => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(BookSyncMerchant::class, 'merchant_id');
    }
}
