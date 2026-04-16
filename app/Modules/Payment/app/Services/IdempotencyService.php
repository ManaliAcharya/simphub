<?php

namespace Modules\Payment\Services;

class IdempotencyService
{
    public function claim(string $key): bool
    {
        return $key !== '';
    }

    // Returns existing transaction if already completed, null if safe to proceed
    // Throws DuplicateRequestException if a concurrent attempt is in flight
    public function acquireLock(string $key): ?Transaction
    {
        return DB::transaction(function () use ($key) {
            $record = IdempotencyKey::where('key', $key)
                ->lockForUpdate()->first();

            if (! $record) {
                throw new IdempotencyKeyNotFoundException($key);
            }

            if ($record->response_status === 'COMPLETED') {
                return $record->transaction; // cached result — no re-charge
            }

            if ($record->response_status === 'IN_FLIGHT') {
                throw new DuplicateRequestException(); // concurrent retry — 409
            }

            $record->update(['response_status' => 'IN_FLIGHT']);
            return null; // safe to proceed
        });
    }

    public function complete(string $key, Transaction $txn): void
    {
        IdempotencyKey::where('key', $key)->update([
            'response_status' => 'COMPLETED',
            'transaction_id'  => $txn->id,
        ]);
    }

}
