<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasUuids;

    /** fee_sync_status: the fee was posted to the client's surcharge income account. */
    public const FEE_SPLIT = 'SPLIT';

    /** fee_sync_status: the fee went in with the invoice payment (e.g. a QuickBooks customer credit). No longer written — kept for payments synced before fees were left out of the invoice payment. */
    public const FEE_NOT_SPLIT = 'NOT_SPLIT';

    /** fee_sync_status: the fee isn't in the PMS at all. */
    public const FEE_NOT_RECORDED = 'NOT_RECORDED';

    /** fee_sync_status: fee sync is off (or the PMS has none), so the fee was left out on purpose. */
    public const FEE_OFF = 'OFF';

    /** fee_sync_status: a NOT_SPLIT/NOT_RECORDED fee that was corrected in the PMS afterwards. */
    public const FEE_FIXED = 'FIXED';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'gateway_response' => 'array',
            'billing_address'  => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function paymentSession(): BelongsTo
    {
        return $this->belongsTo(PaymentSession::class);
    }
}
