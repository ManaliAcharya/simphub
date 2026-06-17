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
            if (! $merchant->default_customer_id || ! $merchant->default_item_id) {
                throw new \RuntimeException('Merchant is missing default customer or item. Re-run QB setup.');
            }

            $surchargeAmount = (float) ($transaction->surcharge_amount ?? 0);

            if ($surchargeAmount > 0 && ! $merchant->surcharge_enabled) {
                throw new \RuntimeException('surcharge_not_enabled: This merchant has not enabled surcharge posting. Enable it in the merchant QB setup.');
            }

            if ($surchargeAmount > 0 && $merchant->surcharge_enabled && ! $merchant->surcharge_item_id) {
                throw new \RuntimeException('Merchant is missing surcharge item configuration. Re-run QB setup.');
            }

            $method          = BookSyncQBClient::normalizePaymentMethod($transaction->payment_method);
            $paymentMethodId = $this->qb->findOrCreatePaymentMethod($merchant, $method);

            $serviceItem   = ['id' => $merchant->default_item_id, 'name' => $merchant->default_item_name];
            $surchargeItem = $merchant->surcharge_enabled && $merchant->surcharge_item_id
                ? ['id' => $merchant->surcharge_item_id, 'name' => $merchant->surcharge_item_name]
                : null;
            $note          = $this->buildPrivateNote($transaction);

            $result = $this->qb->createSalesReceipt(
                merchant:        $merchant,
                customerId:      $merchant->default_customer_id,
                paymentMethodId: $paymentMethodId,
                serviceItem:     $serviceItem,
                amount:          (float) $transaction->amount,
                txnDate:         $transaction->transaction_date->toDateString(),
                docNumber:       $transaction->reference,
                customerName:    $transaction->customer_name,
                privateNote:     $note,
                surchargeItem:   $surchargeItem,
                surchargeAmount: $surchargeAmount,
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
            // surcharge_not_enabled requires human action — never retry
            $noRetry    = str_starts_with($e->getMessage(), 'surcharge_not_enabled:');
            $retryCount = $noRetry ? 7 : ($transaction->retry_count + 1);
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

        $surcharge = (float) ($transaction->surcharge_amount ?? 0);
        if ($surcharge > 0) {
            $parts[] = 'Surcharge: $' . number_format($surcharge, 2);
        }

        $parts[] = 'Posted via BookSync';
        $parts[] = 'Batch: ' . $transaction->batch->batch_id;

        return implode(' | ', $parts);
    }
}
