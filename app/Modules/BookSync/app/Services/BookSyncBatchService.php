<?php

namespace Modules\BookSync\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\BookSync\Jobs\PostTransactionJob;
use Modules\BookSync\Models\BookSyncBatch;
use Modules\BookSync\Models\BookSyncMerchant;
use Modules\BookSync\Models\BookSyncTransaction;

class BookSyncBatchService
{
    /**
     * Accept a validated batch payload, deduplicate, queue new transactions, return batch result.
     */
    /** @return array{batch: BookSyncBatch, duplicates: array} */
    public function process(BookSyncMerchant $merchant, array $payload): array
    {
        $batchDate = $payload['batch_date'] ?? CarbonImmutable::today()->toDateString();

        $batch = BookSyncBatch::create([
            'batch_id'           => $this->generateBatchId($merchant),
            'merchant_id'        => $merchant->id,
            'batch_date'         => $batchDate,
            'total_transactions' => count($payload['transactions']),
            'status'             => 'processing',
        ]);

        // Duplicates are tracked in-memory — not inserted into DB — to avoid
        // violating the unique(merchant_id, reference) constraint.
        $duplicates = [];

        foreach ($payload['transactions'] as $txnData) {
            $reference = $txnData['reference'];
            $txnDate   = $txnData['date'] ?? $batchDate;

            $existing = BookSyncTransaction::where('merchant_id', $merchant->id)
                ->where('reference', $reference)
                ->first();

            if ($existing) {
                // Successfully posted — report as already_posted, do not re-queue
                if (in_array($existing->status, ['posted', 'already_posted'], true)) {
                    $duplicates[] = [
                        'reference'          => $reference,
                        'amount'             => $txnData['amount'],
                        'qb_salesreceipt_id' => $existing->qb_salesreceipt_id,
                        'qb_customer_id'     => $existing->qb_customer_id,
                        'posted_at'          => $existing->posted_at,
                        'original_batch_id'  => $existing->batch?->batch_id,
                    ];
                    continue;
                }

                // Still in queue — do not double-dispatch
                if ($existing->status === 'queued') {
                    $duplicates[] = [
                        'reference' => $reference,
                        'amount'    => $txnData['amount'],
                        'status'    => 'queued',
                    ];
                    continue;
                }

                // Failed or permanently_failed — reset and re-queue
                $existing->forceFill([
                    'batch_id'         => $batch->id,
                    'customer_name'    => $txnData['customer_name'],
                    'customer_email'   => $txnData['customer_email'] ?? null,
                    'amount'           => $txnData['amount'],
                    'surcharge_amount' => isset($txnData['surcharge']) && $txnData['surcharge'] > 0
                        ? $txnData['surcharge'] : null,
                    'payment_method'   => $txnData['payment_method'] ?? 'Other',
                    'transaction_date' => $txnDate,
                    'memo'             => $txnData['memo'] ?? null,
                    'status'           => 'queued',
                    'retry_count'      => 0,
                    'next_retry_at'    => null,
                    'error_message'    => null,
                ])->save();

                PostTransactionJob::dispatch($existing);
                continue;
            }

            $transaction = BookSyncTransaction::create([
                'batch_id'         => $batch->id,
                'merchant_id'      => $merchant->id,
                'reference'        => $reference,
                'customer_name'    => $txnData['customer_name'],
                'customer_email'   => $txnData['customer_email'] ?? null,
                'amount'           => $txnData['amount'],
                'surcharge_amount' => isset($txnData['surcharge']) && $txnData['surcharge'] > 0
                    ? $txnData['surcharge'] : null,
                'payment_method'   => $txnData['payment_method'] ?? 'Other',
                'transaction_date' => $txnDate,
                'memo'             => $txnData['memo'] ?? null,
                'status'           => 'queued',
            ]);

            PostTransactionJob::dispatch($transaction);
        }

        $batch->recalculateCounts($duplicates);

        return ['batch' => $batch->fresh(['transactions']), 'duplicates' => $duplicates];
    }

    public function formatResponse(BookSyncBatch $batch, array $inMemoryDuplicates = []): array
    {
        $dbResults = $batch->transactions->map(fn (BookSyncTransaction $t) => $this->formatTransaction($t))->values()->all();

        $dupResults = array_map(fn (array $d) => array_filter([
            'reference'          => $d['reference'],
            'status'             => 'already_posted',
            'amount'             => (float) $d['amount'],
            'qb_txn_id'          => $d['qb_salesreceipt_id'] ?? null,
            'doc_number'         => $d['reference'],
            'original_batch_id'  => $d['original_batch_id'] ?? null,
        ], fn ($v) => $v !== null), $inMemoryDuplicates);

        return [
            'batch_id'           => $batch->batch_id,
            'batch_date'         => $batch->batch_date->toDateString(),
            'merchant_id'        => $batch->merchant->merchant_id,
            'total_transactions' => $batch->total_transactions,
            'posted'             => $batch->posted,
            'skipped'            => $batch->skipped,
            'failed'             => $batch->failed,
            'queued'             => $batch->queued,
            'results'            => array_merge($dbResults, array_values($dupResults)),
        ];
    }

    public function formatTransaction(BookSyncTransaction $t): array
    {
        $result = [
            'reference'  => $t->reference,
            'status'     => $t->status,
            'amount'     => (float) $t->amount,
        ];

        if ($t->qb_salesreceipt_id) {
            $result['qb_txn_id']  = $t->qb_salesreceipt_id;
            $result['doc_number'] = $t->reference;
        }

        if ($t->status === 'already_posted' && $t->batch) {
            $result['original_batch_id'] = $t->batch->batch_id;
        }

        if (in_array($t->status, ['failed', 'permanently_failed'], true) && $t->error_message) {
            $result['error'] = $t->error_message;
        }

        return $result;
    }

    private function generateBatchId(BookSyncMerchant $merchant): string
    {
        $date   = CarbonImmutable::today()->format('Ymd');
        $suffix = strtolower(substr($merchant->merchant_id, 2, 6));
        $random = strtolower(Str::random(6));

        return "batch_{$date}_{$suffix}_{$random}";
    }
}
