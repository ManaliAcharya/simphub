<?php

namespace Modules\BookSync\Services;

use Modules\BookSync\Models\BookSyncMerchant;
use Modules\BookSync\Models\BookSyncTransaction;
use Throwable;

class BookSyncPostingService
{
    // Error prefixes that must never be retried — require human intervention
    private const NO_RETRY_PREFIXES = [
        'surcharge_not_enabled:',
        'customer_not_found:',
        'payment_method_not_found:',
    ];

    public function __construct(
        private readonly BookSyncQBClient $qb,
    ) {}

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

            // ── Customer resolution ───────────────────────────────────────────
            // Default customer takes priority. Only look up by name when no default is set.
            if ($merchant->default_customer_id) {
                $customerId = $merchant->default_customer_id;
            } elseif ($transaction->customer_name) {
                $customerId = $this->qb->findCustomerByDisplayName($merchant, $transaction->customer_name);
                if (! $customerId) {
                    throw new \RuntimeException(
                        'customer_not_found: No QuickBooks customer found with DisplayName "' . $transaction->customer_name . '"'
                    );
                }
            } else {
                $customerId = null;
            }

            // ── Payment method resolution ─────────────────────────────────────
            $method          = BookSyncQBClient::normalizePaymentMethod($transaction->payment_method);
            $paymentMethodId = $this->qb->findPaymentMethod($merchant, $method);

            if (! $paymentMethodId) {
                throw new \RuntimeException(
                    'payment_method_not_found: No active QuickBooks PaymentMethod named "' . $method . '". Create it in QuickBooks first.'
                );
            }

            $serviceItem   = ['id' => $merchant->default_item_id, 'name' => $merchant->default_item_name];
            $surchargeItem = $merchant->surcharge_enabled && $merchant->surcharge_item_id
                ? ['id' => $merchant->surcharge_item_id, 'name' => $merchant->surcharge_item_name]
                : null;
            $note          = $this->buildPrivateNote($transaction);

            $result = $this->qb->createSalesReceipt(
                merchant:        $merchant,
                customerId:      $customerId,
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
            $noRetry    = $this->isNonRetryableError($e->getMessage());
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

    private function isNonRetryableError(string $message): bool
    {
        foreach (self::NO_RETRY_PREFIXES as $prefix) {
            if (str_starts_with($message, $prefix)) {
                return true;
            }
        }

        return false;
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
