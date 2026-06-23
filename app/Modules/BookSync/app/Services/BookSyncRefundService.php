<?php

namespace Modules\BookSync\Services;

use Modules\BookSync\Models\BookSyncMerchant;
use Modules\BookSync\Models\BookSyncRefund;
use Modules\BookSync\Models\BookSyncTransaction;
use RuntimeException;

class BookSyncRefundService
{
    public function __construct(
        private readonly BookSyncQBClient $qb,
    ) {}

    /**
     * Create a QuickBooks Refund Receipt and record it in booksync_refunds.
     * Returns a response array ready to be JSON-encoded.
     *
     * @throws RuntimeException if the QB call fails or merchant setup is incomplete
     */
    public function createRefund(BookSyncMerchant $merchant, array $data): array
    {
        $reference = $data['reference'];

        // Idempotency — return existing record if already posted
        $existing = BookSyncRefund::where('merchant_id', $merchant->id)
            ->where('reference', $reference)
            ->first();

        if ($existing && $existing->status === 'posted') {
            return [
                'status'               => 'already_posted',
                'reference'            => $existing->reference,
                'original_reference'   => $existing->original_reference,
                'amount'               => $existing->amount,
                'qb_refundreceipt_id'  => $existing->qb_refundreceipt_id,
                'posted_at'            => $existing->posted_at?->toIso8601String(),
            ];
        }

        if (! $merchant->default_customer_id || ! $merchant->default_item_id) {
            throw new RuntimeException('Merchant is missing default customer or item. Re-run QB setup.');
        }

        $method          = BookSyncQBClient::normalizePaymentMethod($data['payment_method'] ?? 'Other');
        $paymentMethodId = $this->qb->findPaymentMethod($merchant, $method);

        if (! $paymentMethodId) {
            throw new RuntimeException(
                'payment_method_not_found: No active QuickBooks PaymentMethod named "' . $method . '". Create it in QuickBooks first.'
            );
        }

        $serviceItem = ['id' => $merchant->default_item_id, 'name' => $merchant->default_item_name];
        $txnDate     = $data['transaction_date'] ?? now()->toDateString();
        $memo        = $data['memo'] ?? null;
        $privateNote = implode(' | ', array_filter([
            $memo,
            'Refund posted via BookSync',
            isset($data['original_reference']) ? 'Original: ' . $data['original_reference'] : null,
        ]));

        $result = $this->qb->createRefundReceipt(
            merchant:        $merchant,
            customerId:      $merchant->default_customer_id,
            paymentMethodId: $paymentMethodId,
            serviceItem:     $serviceItem,
            amount:          (float) $data['amount'],
            txnDate:         $txnDate,
            docNumber:       $reference,
            customerName:    $data['customer_name'] ?? null,
            privateNote:     $privateNote ?: null,
        );

        $record = BookSyncRefund::create([
            'merchant_id'          => $merchant->id,
            'reference'            => $reference,
            'original_reference'   => $data['original_reference'] ?? null,
            'customer_name'        => $data['customer_name'] ?? 'Walk-in',
            'customer_email'       => $data['customer_email'] ?? null,
            'amount'               => (float) $data['amount'],
            'payment_method'       => $method,
            'transaction_date'     => $txnDate,
            'memo'                 => $memo,
            'status'               => 'posted',
            'qb_refundreceipt_id'  => $result['qb_refundreceipt_id'],
            'qb_customer_id'       => $result['qb_customer_id'],
            'posted_at'            => now(),
        ]);

        return [
            'status'              => 'posted',
            'reference'           => $record->reference,
            'original_reference'  => $record->original_reference,
            'amount'              => $record->amount,
            'qb_refundreceipt_id' => $record->qb_refundreceipt_id,
            'posted_at'           => $record->posted_at->toIso8601String(),
        ];
    }

    /**
     * Void an existing Sales Receipt in QuickBooks.
     * Looks up the original transaction by reference, voids it, and marks it voided locally.
     *
     * @throws RuntimeException if QB call fails
     */
    public function voidTransaction(BookSyncMerchant $merchant, string $originalReference): array
    {
        $transaction = BookSyncTransaction::where('merchant_id', $merchant->id)
            ->where('reference', $originalReference)
            ->first();

        if (! $transaction) {
            throw new \InvalidArgumentException(
                "Transaction with reference \"{$originalReference}\" not found for this merchant."
            );
        }

        if ($transaction->status === 'voided') {
            return [
                'status'             => 'already_voided',
                'original_reference' => $originalReference,
                'qb_salesreceipt_id' => $transaction->qb_salesreceipt_id,
            ];
        }

        if (! $transaction->qb_salesreceipt_id) {
            throw new RuntimeException(
                "Transaction \"{$originalReference}\" has no QuickBooks Sales Receipt ID. It may not have been successfully posted."
            );
        }

        $this->qb->voidSalesReceipt($merchant, $transaction->qb_salesreceipt_id);

        $transaction->forceFill(['status' => 'voided'])->save();

        return [
            'status'             => 'voided',
            'original_reference' => $originalReference,
            'qb_salesreceipt_id' => $transaction->qb_salesreceipt_id,
            'voided_at'          => now()->toIso8601String(),
        ];
    }
}
