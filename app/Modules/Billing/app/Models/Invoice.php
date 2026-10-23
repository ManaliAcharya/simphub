<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Invoice extends Model
{
    use HasUuids;

    /** Statuses a paid invoice may still move to; anything else would mark it unpaid again. */
    private const STATUSES_AFTER_PAID = ['PAID', 'REFUNDED', 'PARTIALLY_REFUNDED', 'CANCELLED', 'VOID', 'VOIDED'];

    protected $guarded = [];

    protected static function booted(): void
    {
        // Once SimpHub has captured a payment the invoice is PAID, whatever happens
        // afterwards — a PMS sync that still shows a balance (e.g. the payment wasn't
        // recorded in QuickBooks yet) must not flip it back to PENDING/SENT. Refunds and
        // cancellations still apply.
        static::saving(function (Invoice $invoice): void {
            if (! $invoice->exists || ! $invoice->isDirty('status')) {
                return;
            }

            if (in_array(strtoupper((string) $invoice->status), self::STATUSES_AFTER_PAID, true)) {
                return;
            }

            $captured = DB::table('transactions')
                ->where('invoice_id', $invoice->id)
                ->where('status', 'CAPTURED')
                ->exists();

            if ($captured) {
                $invoice->status = 'PAID';
            }
        });
    }

    protected function casts(): array
    {
        return [
            'raw_payload'      => 'array',
            'recipient_emails' => 'array',
            'customer'         => 'array',
            'metadata'         => 'array',
            'synced_at'        => 'datetime',
        ];
    }
}
