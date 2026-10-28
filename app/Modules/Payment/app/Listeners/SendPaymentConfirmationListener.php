<?php

namespace Modules\Payment\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\EmailConfiguration;
use Modules\Inbound\Models\QuickBooksConnection;
use Modules\Payment\Events\PaymentApproved;
use Modules\Payment\Mail\PaymentConfirmationMail;
use Modules\Payment\Services\AchSettlementService;
use Modules\Payment\Services\MerchantNotificationService;

class SendPaymentConfirmationListener
{
    public function __construct(
        private readonly AchSettlementService $achSettlements,
        private readonly MerchantNotificationService $merchantNotifications,
    ) {}

    public function handle(PaymentApproved $event): void
    {
        $transaction = Transaction::with('invoice')->find($event->transactionReference);

        if (! $transaction || ! $transaction->invoice) {
            return;
        }

        $invoice = $transaction->invoice;

        $client = $invoice->pms_client_id
            ? Client::query()->where('pms_client_id', $invoice->pms_client_id)->first()
            : null;

        $emailConfig = $client
            ? EmailConfiguration::where('client_id', $client->id)->first()
            : null;

        $fromName = $this->resolveFromName($invoice, $client, $emailConfig);

        // ── Customer confirmation ─────────────────────────────────────────────
        $customerEmail = trim((string) ($invoice->customer['email'] ?? ''));
        if ($customerEmail === '') {
            $customerEmail = trim((string) ($invoice->recipient_emails[0] ?? ''));
        }

        if ($customerEmail !== '') {
            try {
                Mail::to($customerEmail)->send(
                    new PaymentConfirmationMail($transaction, $invoice, $client, $emailConfig, $fromName)
                );
            } catch (\Throwable $e) {
                Log::error('SendPaymentConfirmationListener: failed to send customer confirmation', [
                    'invoice_id'     => $invoice->id,
                    'customer_email' => $customerEmail,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        // ── Merchant notification ─────────────────────────────────────────────
        // ACH isn't money in the bank until it settles, so the firm hears about it from
        // PollAchSettlementsJob instead — once Paya's status lookup is set up.
        if (! $this->achSettlements->holdsMerchantNotification($transaction)) {
            $this->merchantNotifications->sendPaymentNotification($transaction);
        }
    }

    private function resolveFromName(Invoice $invoice, ?Client $client, ?EmailConfiguration $emailConfig = null): ?string
    {
        if ($client === null) {
            return null;
        }

        if ((string) $invoice->pms_source === 'quickbooks') {
            $connection = QuickBooksConnection::query()
                ->where('provider', 'quickbooks')
                ->where('pms_client_id', $client->pms_client_id)
                ->first();

            $name = $connection?->companyName() ?? '';

            return $name !== '' ? $name : null;
        }

        $name = (string) ($emailConfig?->from_name ?? '');

        return $name !== '' ? $name : null;
    }
}
