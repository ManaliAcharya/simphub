<?php

namespace Modules\Payment\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Models\PaymentSessionReminder;
use Modules\Inbound\Models\Client;
use Modules\Payment\Services\InvoiceLiveStatusResolver;
use Modules\Payment\Services\PaymentLinkService;

class SendInvoiceReminder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly string $paymentSessionId,
    ) {}

    public function handle(InvoiceLiveStatusResolver $resolver, PaymentLinkService $paymentLinks): void
    {
        Log::info('SendInvoiceReminder started', ['payment_session_id' => $this->paymentSessionId]);

        $session = PaymentSession::query()->with('invoice')->find($this->paymentSessionId);

        if (! $session || $session->link_status !== 'active' || ! $session->invoice || ! $session->first_email_sent_at) {
            Log::warning('SendInvoiceReminder aborted: session/invoice guard failed', [
                'payment_session_id'  => $this->paymentSessionId,
                'session_found'       => (bool) $session,
                'link_status'         => $session?->link_status,
                'has_invoice'         => (bool) $session?->invoice,
                'first_email_sent_at' => $session?->first_email_sent_at?->toDateTimeString(),
            ]);
            return;
        }

        $invoice = $session->invoice;
        $client  = $invoice->pms_client_id
            ? Client::query()->where('pms_client_id', $invoice->pms_client_id)->first()
            : null;

        if (! $client || ! $client->reminders_enabled) {
            Log::info('SendInvoiceReminder aborted: reminders disabled or client not found', [
                'payment_session_id' => $session->id,
                'pms_client_id'      => $invoice->pms_client_id,
                'client_found'       => (bool) $client,
                'reminders_enabled'  => $client?->reminders_enabled,
            ]);
            $session->forceFill(['next_reminder_at' => null])->save();
            return;
        }

        $schedule  = $client->reminderScheduleDays();
        $stepIndex = $session->reminders_sent_count;

        if ($stepIndex >= count($schedule)) {
            Log::info('SendInvoiceReminder aborted: cadence exhausted', [
                'payment_session_id' => $session->id,
                'step_index'         => $stepIndex,
                'schedule_length'    => count($schedule),
            ]);
            $session->forceFill(['next_reminder_at' => null])->save();
            return;
        }

        $status = $resolver->resolve($invoice);

        Log::info('SendInvoiceReminder resolved invoice status', [
            'payment_session_id' => $session->id,
            'invoice_id'         => $invoice->id,
            'status'             => $status,
        ]);

        if ($status === 'paid' || $status === 'gone') {
            $session->forceFill([
                'link_status'      => $status === 'paid' ? 'paid' : $session->link_status,
                'next_reminder_at' => null,
            ])->save();

            AuditLogger::log('payment_reminder_stopped', 'payment_session', $session->id, [
                'invoice_id' => $invoice->id,
                'reason'     => $status,
            ]);

            return;
        }

        if ($status === 'unknown') {
            Log::warning('SendInvoiceReminder: invoice status unknown, releasing for retry in 30 min', [
                'payment_session_id' => $session->id,
                'invoice_id'         => $invoice->id,
            ]);
            $this->release(now()->addMinutes(30));
            return;
        }

        $stepIndex      = $this->resolveCatchUpStep($session, $schedule, $stepIndex);
        $reminderNumber = $stepIndex + 1;
        $dayOffset      = $schedule[$stepIndex];
        $recipient      = $client->payment_link_override_enabled
            ? ($client->payment_link_recipient ?? 'customer')
            : 'customer';

        Log::info('SendInvoiceReminder attempting send', [
            'payment_session_id' => $session->id,
            'reminder_number'    => $reminderNumber,
            'day_offset'         => $dayOffset,
            'recipient_emails'   => $invoice->recipient_emails ?? [],
        ]);

        try {
            $emailsSent = $paymentLinks->sendReminder($invoice, $session, (array) ($invoice->recipient_emails ?? []));

            if ($emailsSent === 0) {
                Log::warning('SendInvoiceReminder: no recipient email, skipping send', [
                    'payment_session_id' => $session->id,
                    'reminder_number'    => $reminderNumber,
                ]);

                PaymentSessionReminder::updateOrCreate(
                    ['payment_session_id' => $session->id, 'reminder_number' => $reminderNumber],
                    ['day_offset' => $dayOffset, 'status' => 'skipped', 'reason' => 'no_customer_email', 'recipient' => $recipient],
                );

                AuditLogger::log('payment_reminder_skipped', 'payment_session', $session->id, [
                    'invoice_id'      => $invoice->id,
                    'reminder_number' => $reminderNumber,
                    'reason'          => 'no_customer_email',
                ]);

                $this->advance($session, $schedule, $reminderNumber);
                return;
            }

            PaymentSessionReminder::create([
                'payment_session_id' => $session->id,
                'reminder_number'    => $reminderNumber,
                'day_offset'         => $dayOffset,
                'status'             => 'sent',
                'recipient'          => $recipient,
            ]);

            $this->advance($session, $schedule, $reminderNumber);

            Log::info('SendInvoiceReminder sent successfully', [
                'payment_session_id' => $session->id,
                'reminder_number'    => $reminderNumber,
                'emails_sent'        => $emailsSent,
            ]);

            AuditLogger::log('payment_reminder_sent', 'payment_session', $session->id, [
                'invoice_id'      => $invoice->id,
                'reminder_number' => $reminderNumber,
                'day_offset'      => $dayOffset,
            ]);
        } catch (\Throwable $e) {
            Log::error('SendInvoiceReminder failed with exception', [
                'payment_session_id' => $session->id,
                'reminder_number'    => $reminderNumber,
                'error'              => $e->getMessage(),
            ]);

            PaymentSessionReminder::updateOrCreate(
                ['payment_session_id' => $session->id, 'reminder_number' => $reminderNumber],
                ['day_offset' => $dayOffset, 'status' => 'failed', 'reason' => $e->getMessage(), 'recipient' => $recipient],
            );

            AuditLogger::log('payment_reminder_failed', 'payment_session', $session->id, [
                'invoice_id'      => $invoice->id,
                'reminder_number' => $reminderNumber,
                'reason'          => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function advance(PaymentSession $session, array $schedule, int $newCount): void
    {
        $nextAt = $newCount < count($schedule)
            ? $session->first_email_sent_at->copy()->addDays((int) $schedule[$newCount])
            : null;

        $session->forceFill([
            'reminders_sent_count'  => $newCount,
            'last_reminder_sent_at' => now(),
            'next_reminder_at'      => $nextAt,
        ])->save();
    }

    /**
     * catch_up_mode = 'latest': if the scheduler was down and several cadence steps are
     * now overdue, send only the highest applicable one and log the intermediate steps
     * as skipped, instead of bursting through every missed step.
     */
    private function resolveCatchUpStep(PaymentSession $session, array $schedule, int $stepIndex): int
    {
        if (config('reminders.catch_up_mode', 'latest') !== 'latest') {
            return $stepIndex;
        }

        $daysElapsed = (int) $session->first_email_sent_at->diffInDays(now());
        $target      = $stepIndex;

        for ($i = $stepIndex; $i < count($schedule); $i++) {
            if ($schedule[$i] <= $daysElapsed) {
                $target = $i;
            }
        }

        for ($i = $stepIndex; $i < $target; $i++) {
            PaymentSessionReminder::firstOrCreate(
                ['payment_session_id' => $session->id, 'reminder_number' => $i + 1],
                ['day_offset' => $schedule[$i], 'status' => 'skipped', 'reason' => 'catch_up_collapsed'],
            );
        }

        return $target;
    }
}
