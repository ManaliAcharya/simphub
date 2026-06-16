<?php

namespace Modules\BookSync\Services;

use Modules\BookSync\Models\BookSyncMerchant;
use Modules\BookSync\Models\BookSyncTransaction;
use Throwable;

class BookSyncPostingService
{
    public function __construct(
        private readonly BookSyncQBClient $qb,
    ) {}

    /**
     * Post a single transaction to QuickBooks.
     * Returns the updated transaction record.
     */
    public function post(BookSyncTransaction $transaction): BookSyncTransaction
    {
        $merchant = $transaction->merchant;

        try {
            $customerId = $this->qb->findOrCreateCustomer(
                $merchant,
                $transaction->customer_name,
                $transaction->customer_email,
            );

            $method      = BookSyncQBClient::normalizePaymentMethod($transaction->payment_method);
            $paymentMethodId = $this->qb->findOrCreatePaymentMethod($merchant, $method);
            $serviceItem = $this->qb->findOrCreateServiceItem($merchant);

            $note = $this->buildPrivateNote($transaction);

            $result = $this->qb->createSalesReceipt(
                merchant:        $merchant,
                customerId:      $customerId,
                paymentMethodId: $paymentMethodId,
                serviceItem:     $serviceItem,
                amount:          (float) $transaction->amount,
                txnDate:         $transaction->transaction_date->toDateString(),
                docNumber:       $transaction->reference,
                privateNote:     $note,
            );

            $transaction->forceFill([
                'status'             => 'posted',
                'qb_salesreceipt_id' => $result['qb_salesreceipt_id'],
                'qb_customer_id'     => $result['qb_customer_id'],
                'posted_at'          => now(),
                'error_message'      => null,
                'next_retry_at'      => null,
            ])->save();

        } catch (Throwable $e) {
            $retryCount = $transaction->retry_count + 1;
            $exhausted  = $retryCount >= 7;

            $transaction->forceFill([
                'status'        => $exhausted ? 'permanently_failed' : 'failed',
                'error_message' => $e->getMessage(),
                'retry_count'   => $retryCount,
                'next_retry_at' => $exhausted
                    ? null
                    : now()->addSeconds(BookSyncTransaction::retryDelaySeconds($retryCount + 1)),
            ])->save();
        }

        return $transaction->fresh();
    }

    private function buildPrivateNote(BookSyncTransaction $transaction): string
    {
        $parts = [];

        if ($transaction->memo) {
            $parts[] = $transaction->memo;
        }

        $parts[] = 'Posted via BookSync';
        $parts[] = 'Batch: ' . $transaction->batch->batch_id;

        return implode(' | ', $parts);
    }
}
