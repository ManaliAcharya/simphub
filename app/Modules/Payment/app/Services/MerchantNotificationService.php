<?php

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\EmailConfiguration;
use Modules\Payment\Mail\AchReturnedNotificationMail;
use Modules\Payment\Mail\MerchantPaymentNotificationMail;

/**
 * Emails the firm (merchant) about a payment: the "You got paid" notice, and for ACH the
 * returned-payment notice. Goes to the client's reply-to address, else its payment-link
 * admin email.
 */
class MerchantNotificationService
{
    /** Sends "You got paid" once per transaction; true when it went out (or already had). */
    public function sendPaymentNotification(Transaction $transaction): bool
    {
        if ($transaction->merchant_notified_at) {
            return true;
        }

        [$client, $email] = $this->recipient($transaction);

        if ($email === '') {
            return false;
        }

        try {
            Mail::to($email)->send(new MerchantPaymentNotificationMail($transaction, $transaction->invoice, $client));
        } catch (\Throwable $e) {
            Log::error('MerchantNotificationService: failed to send merchant notification', [
                'invoice_id'     => $transaction->invoice_id,
                'merchant_email' => $email,
                'error'          => $e->getMessage(),
            ]);

            return false;
        }

        $transaction->forceFill(['merchant_notified_at' => now()])->save();

        return true;
    }

    public function sendAchReturnedNotice(Transaction $transaction): bool
    {
        [$client, $email] = $this->recipient($transaction);

        if ($email === '') {
            Log::warning('MerchantNotificationService: returned ACH payment has no firm email to notify', [
                'transaction_id' => $transaction->id,
                'invoice_id'     => $transaction->invoice_id,
            ]);

            return false;
        }

        try {
            Mail::to($email)->send(new AchReturnedNotificationMail($transaction, $transaction->invoice, $client));
        } catch (\Throwable $e) {
            Log::error('MerchantNotificationService: failed to send returned ACH notice', [
                'transaction_id' => $transaction->id,
                'merchant_email' => $email,
                'error'          => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /** @return array{0: ?Client, 1: string} */
    private function recipient(Transaction $transaction): array
    {
        $invoice = $transaction->invoice;

        $client = $invoice?->pms_client_id
            ? Client::query()->where('pms_client_id', $invoice->pms_client_id)->first()
            : null;

        $emailConfig = $client
            ? EmailConfiguration::where('client_id', $client->id)->first()
            : null;

        $email = trim((string) ($emailConfig?->reply_to_email ?? ''));
        if ($email === '') {
            $email = trim((string) ($client?->payment_link_admin_email ?? ''));
        }

        return [$client, $email];
    }
}
