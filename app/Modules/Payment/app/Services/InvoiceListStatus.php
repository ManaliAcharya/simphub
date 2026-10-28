<?php

namespace Modules\Payment\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Models\AuditLog;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;

/**
 * The Email and Payment status columns of the invoice list.
 *
 * invoices.status can't be shown for either: every PMS sync overwrites it with the
 * PMS's own value (QuickBooks' EmailStatus, Zoho/Clio invoice states) and checkout
 * writes FAILED/PAID into the same field, so it flips between the two. Each column
 * here is read from one source instead — our send records for Email, our payment
 * session and transactions for Payment.
 */
class InvoiceListStatus
{
    private const EMAIL_FAILURE_EVENTS   = ['payment_link.initial_send_failed', 'payment_link.resend_failed'];
    private const PAYMENT_FAILURE_EVENTS = ['PAYMENT_DECLINED', 'PAYMENT_ATTEMPT_FAILED'];

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return Collection<string, array{email: array, payment: array}> keyed by invoice id
     */
    public function forInvoices(Collection $invoices): Collection
    {
        $invoiceIds = $invoices->pluck('id')->all();

        $sessions = collect();
        $transactions = collect();
        foreach (array_chunk($invoiceIds, 1000) as $chunk) {
            $sessions = $sessions->concat(PaymentSession::query()
                ->whereIn('invoice_id', $chunk)
                ->orderByDesc('created_at')
                ->get([
                    'id', 'invoice_id', 'status', 'link_status', 'payment_link_sent_at',
                    'payment_link_last_sent_to', 'last_email_sent_at', 'reminders_sent_count',
                    'last_reminder_sent_at',
                ]));
            $transactions = $transactions->concat(DB::table('transactions')
                ->whereIn('invoice_id', $chunk)
                ->get(['invoice_id', 'status']));
        }

        $latestSessionByInvoice = $sessions->unique('invoice_id')->keyBy('invoice_id');
        $transactionStatuses    = $transactions->groupBy('invoice_id')
            ->map(fn (Collection $rows) => $rows->pluck('status')->map(fn ($s) => strtoupper((string) $s))->all());

        $failures = $this->auditHistory($latestSessionByInvoice->pluck('id')->all());

        return $invoices->mapWithKeys(function (Invoice $invoice) use ($latestSessionByInvoice, $transactionStatuses, $failures) {
            $session = $latestSessionByInvoice->get($invoice->id);

            return [$invoice->id => [
                'email'   => $this->emailStatus(
                    $invoice,
                    $session,
                    $session ? ($failures['email'][$session->id] ?? null) : null,
                    $session && isset($failures['held'][$session->id]),
                ),
                'payment' => $this->paymentStatus(
                    $invoice,
                    $session,
                    $transactionStatuses->get($invoice->id, []),
                    $session ? ($failures['payment'][$session->id] ?? null) : null,
                ),
            ]];
        });
    }

    /**
     * @param  array{at: Carbon, reason: ?string}|null  $lastFailure  latest send that reached nobody
     */
    public function emailStatus(Invoice $invoice, ?PaymentSession $session, ?array $lastFailure, bool $held = false): array
    {
        if (! $session || ! $session->payment_link_sent_at) {
            return $this->status('Not sent', 'neutral', null, match (true) {
                $held                              => 'Held: trust payment links are turned off for this client',
                empty($invoice->recipient_emails)  => 'No recipient email on the invoice',
                default                            => null,
            });
        }

        $reminders = (int) ($session->reminders_sent_count ?? 0);
        $reminderNote = $reminders > 0
            ? $reminders.' '.str('reminder')->plural($reminders).' sent, last '.$session->last_reminder_sent_at?->format('M j, Y')
            : null;

        // The first send's claim is set before sending; its recipient list is then
        // corrected to who actually got it, so an empty list means it reached nobody.
        $firstSendDelivered = ! empty($session->payment_link_last_sent_to) || $session->last_email_sent_at;
        $lastDeliveredAt    = $session->last_email_sent_at ?? ($firstSendDelivered ? $session->payment_link_sent_at : null);

        if ($lastFailure && (! $lastDeliveredAt || $lastFailure['at']->gt($lastDeliveredAt))) {
            return $this->status('Send failed', 'danger', $lastFailure['at'], $lastFailure['reason']);
        }

        if (! $lastDeliveredAt) {
            return $this->status('Send failed', 'danger', $session->payment_link_sent_at);
        }

        return $session->last_email_sent_at
            ? $this->status('Resent', 'success', $session->last_email_sent_at, $reminderNote)
            : $this->status('Sent', 'success', $session->payment_link_sent_at, $reminderNote);
    }

    /**
     * @param  string[]  $transactionStatuses  upper-cased statuses of the invoice's transactions
     * @param  array{at: Carbon, reason: ?string}|null  $lastFailure  latest failed payment attempt
     */
    public function paymentStatus(Invoice $invoice, ?PaymentSession $session, array $transactionStatuses, ?array $lastFailure): array
    {
        $invoiceStatus = strtoupper((string) $invoice->status);
        $sessionStatus = strtoupper((string) $session?->status);
        $linkStatus    = strtolower((string) ($session?->link_status ?? 'active'));

        if (in_array('REFUNDED', $transactionStatuses, true) || $invoiceStatus === 'REFUNDED') {
            return $this->status('Refunded', 'warning');
        }

        if (in_array('CAPTURED', $transactionStatuses, true)) {
            return $this->status('Success', 'success');
        }

        // Paid outside SimpHub — the PMS reports it settled.
        if ($invoiceStatus === 'PAID') {
            return $this->status('Success', 'success', null, 'Marked paid in the PMS');
        }

        if (in_array('RETURNED', $transactionStatuses, true)) {
            return $this->status('Returned', 'danger', null, 'ACH payment was returned by the bank');
        }

        if (in_array($invoiceStatus, ['CANCELLED', 'VOIDED', 'VOID'], true) || $linkStatus === 'voided') {
            return $this->status('Voided', 'neutral');
        }

        if ($linkStatus === 'disabled') {
            return $this->status('Link disabled', 'neutral', null, 'Invoice was removed in the PMS');
        }

        if ($sessionStatus === 'PROCESSING') {
            return $this->status('Processing', 'info');
        }

        if ($sessionStatus === 'FAILED' || $invoiceStatus === 'FAILED') {
            return $this->status('Failed', 'danger', $lastFailure['at'] ?? null, $lastFailure['reason'] ?? null);
        }

        return $this->status('Pending', 'neutral');
    }

    /**
     * Latest email failure that reached nobody, and latest failed payment attempt, per session.
     *
     * Also which sessions had their link held (Lawcus trust links with sending off).
     *
     * @return array{email: array<string, array>, payment: array<string, array>, held: array<string, true>}
     */
    private function auditHistory(array $sessionIds): array
    {
        $result = ['email' => [], 'payment' => [], 'held' => []];

        foreach (array_chunk($sessionIds, 1000) as $chunk) {
            $rows = AuditLog::query()
                ->where('entity_type', 'payment_session')
                ->whereIn('entity_id', $chunk)
                ->whereIn('event_type', [...self::EMAIL_FAILURE_EVENTS, ...self::PAYMENT_FAILURE_EVENTS, 'PAYMENT_LINK_HELD'])
                ->orderByDesc('id')
                ->get(['entity_id', 'event_type', 'payload', 'created_at']);

            foreach ($rows as $row) {
                $payload = (array) $row->payload;
                $at      = Carbon::parse($row->created_at);

                if ($row->event_type === 'PAYMENT_LINK_HELD') {
                    $result['held'][$row->entity_id] = true;
                    continue;
                }

                if (in_array($row->event_type, self::PAYMENT_FAILURE_EVENTS, true)) {
                    $result['payment'][$row->entity_id] ??= ['at' => $at, 'reason' => $payload['message'] ?? null];
                    continue;
                }

                // Partial failures still reached someone — only a send that reached nobody counts.
                $reachedNobody = $row->event_type === 'payment_link.initial_send_failed'
                    ? empty($payload['sent_emails'])
                    : count((array) ($payload['failed_emails'] ?? [])) >= count((array) ($payload['attempted_emails'] ?? []));

                if ($reachedNobody) {
                    $reasons = array_values((array) ($payload['failure_reasons'] ?? []));
                    $result['email'][$row->entity_id] ??= ['at' => $at, 'reason' => $reasons[0] ?? null];
                }
            }
        }

        return $result;
    }

    private function status(string $label, string $tone, ?Carbon $at = null, ?string $detail = null): array
    {
        return ['label' => $label, 'tone' => $tone, 'at' => $at, 'detail' => $detail];
    }
}
