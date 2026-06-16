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
    public function process(BookSyncMerchant $merchant, array $payload): BookSyncBatch
    {
        $batchDate = $payload['batch_date'] ?? CarbonImmutable::today()->toDateString();

        $batch = BookSyncBatch::create([
            'batch_id'           => $this->generateBatchId($merchant),
            'merchant_id'        => $merchant->id,
            'batch_date'         => $batchDate,
            'total_transactions' => count($payload['transactions']),
            'status'             => 'processing',
        ]);

        foreach ($payload['transactions'] as $txnData) {
            $reference = $txnData['reference'];
            $txnDate   = $txnData['date'] ?? $batchDate;

            $existing = BookSyncTransaction::where('merchant_id', $merchant->id)
                ->where('reference', $reference)
                ->where('status', 'posted')
                ->first();

            if ($existing) {
                BookSyncTransaction::create([
                    'batch_id'        => $batch->id,
                    'merchant_id'     => $merchant->id,
                    'reference'       => $reference,
                    'customer_name'   => $txnData['customer_name'],
                    'customer_email'  => $txnData['customer_email'] ?? null,
                    'amount'          => $txnData['amount'],
                    'payment_method'  => $txnData['payment_method'] ?? 'Other',
                    'transaction_date' => $txnDate,
                    'memo'            => $txnData['memo'] ?? null,
                    'status'          => 'already_posted',
                    'qb_salesreceipt_id' => $existing->qb_salesreceipt_id,
                    'qb_customer_id'  => $existing->qb_customer_id,
                    'posted_at'       => $existing->posted_at,
                ]);
                continue;
            }

            $transaction = BookSyncTransaction::create([
                'batch_id'         => $batch->id,
                'merchant_id'      => $merchant->id,
                'reference'        => $reference,
                'customer_name'    => $txnData['customer_name'],
                'customer_email'   => $txnData['customer_email'] ?? null,
                'amount'           => $txnData['amount'],
                'payment_method'   => $txnData['payment_method'] ?? 'Other',
                'transaction_date' => $txnDate,
                'memo'             => $txnData['memo'] ?? null,
                'status'           => 'queued',
            ]);

            PostTransactionJob::dispatch($transaction);
        }

        $batch->recalculateCounts();

        return $batch->fresh(['transactions']);
    }

    public function formatResponse(BookSyncBatch $batch): array
    {
        return [
            'batch_id'           => $batch->batch_id,
            'batch_date'         => $batch->batch_date->toDateString(),
            'merchant_id'        => $batch->merchant->merchant_id,
            'total_transactions' => $batch->total_transactions,
            'posted'             => $batch->posted,
            'skipped'            => $batch->skipped,
            'failed'             => $batch->failed,
            'queued'             => $batch->queued,
            'results'            => $batch->transactions->map(fn (BookSyncTransaction $t) => $this->formatTransaction($t))->values()->all(),
        ];
    }

    public function formatTransaction(BookSyncTransaction $t): array
    {
        $result = [
            'reference' => $t->reference,
            'status'    => $t->status,
            'amount'    => (float) $t->amount,
        ];

        if ($t->qb_salesreceipt_id) {
            $result['qb_salesreceipt_id'] = $t->qb_salesreceipt_id;
        }

        if ($t->qb_customer_id) {
            $result['qb_customer_id'] = $t->qb_customer_id;
        }

        if ($t->status === 'already_posted' && $t->posted_at) {
            $result['message'] = 'Transaction with this reference was posted on ' . $t->posted_at->toIso8601String();
        }

        if ($t->status === 'failed' && $t->error_message) {
            $result['error'] = $t->error_message;
        }

        return $result;
    }

    private function generateBatchId(BookSyncMerchant $merchant): string
    {
        $date   = CarbonImmutable::today()->format('Ymd');
        $suffix = strtolower(substr($merchant->merchant_id, 2, 6));

        return "batch_{$date}_{$suffix}";
    }
}
