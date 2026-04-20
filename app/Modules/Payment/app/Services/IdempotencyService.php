<?php

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Transaction;
use Modules\Payment\Models\IdempotencyKey;
use RuntimeException;

class IdempotencyService
{
    public function claim(string $key): bool
    {
        return $key !== '';
    }

    public function acquireLock(string $key): ?Transaction
    {
        return DB::transaction(function () use ($key) {
            $record = IdempotencyKey::query()
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if (! $record) {
                throw new RuntimeException("Missing idempotency key [{$key}].");
            }

            if ($record->response_status === 'COMPLETED') {
                return $record->transaction_id
                    ? Transaction::query()->find($record->transaction_id)
                    : null;
            }

            if ($record->response_status === 'IN_FLIGHT') {
                throw new RuntimeException('Payment is already being processed for this session.');
            }

            $record->update(['response_status' => 'IN_FLIGHT']);

            return null;
        });
    }

    public function complete(string $key, Transaction $transaction): void
    {
        IdempotencyKey::query()->where('key', $key)->update([
            'response_status' => 'COMPLETED',
            'transaction_id' => $transaction->id,
        ]);
    }

    public function fail(string $key): void
    {
        IdempotencyKey::query()->where('key', $key)->update([
            'response_status' => 'FAILED',
        ]);
    }
}
