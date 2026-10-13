<?php

namespace Modules\Inbound\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Inbound\Services\SubscriptionService;
use Modules\Payment\Events\PaymentApproved;
use Modules\Billing\Models\Transaction;

class ActivateSubscriptionOnPayment
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function handle(PaymentApproved $event): void
    {
        try {
            $transaction = Transaction::with('invoice')->find($event->transactionReference);

            if ($transaction?->invoice) {
                $this->subscriptions->markInvoicePaid($transaction->invoice);
            }
        } catch (\Throwable $e) {
            // Never let subscription bookkeeping break the payment flow.
            Log::error('ActivateSubscriptionOnPayment failed', [
                'transaction_id' => $event->transactionReference,
                'error'          => $e->getMessage(),
            ]);
        }
    }
}
