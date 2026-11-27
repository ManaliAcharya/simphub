<?php

namespace Modules\Payment\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Audit\Models\AuditLog;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Models\Transaction;

/**
 * Plain-English history of one invoice for the invoice list's "History" button,
 * built from the audit log so the merchant can see what has happened so far.
 *
 * Every step of a payment page visit is grouped into one entry for that visit.
 * Internal steps (routing, gateway calls, debug rows) are left out.
 */
class InvoiceActivityLog
{
    /** Hard cap so a very busy invoice can't make the page slow. */
    private const MAX_ROWS = 2000;

    /** Steps of a payment page visit. Grouped by visit instead of listed one by one. */
    private const VISIT_EVENTS = [
        'CHECKOUT_PAGE_OPENED', 'CHECKOUT_LOADED', 'CHECKOUT_LOAD_FAILED',
        'PAYA_FORM_ISSUED', 'PAYA_FORM_UNAVAILABLE', 'BANK_DETAILS_REJECTED', 'BANK_DETAILS_SECURED',
        'PAYMENT_SUBMITTED', 'PAYMENT_ATTEMPT_FAILED', 'PAYMENT_ATTEMPT_SUCCEEDED',
    ];

    /** Internal rows that mean nothing to a merchant, or repeat another entry. */
    private const HIDDEN_EVENTS = [
        'ROUTING_DECISION_MADE', 'GATEWAY_CALL_STARTED', 'GATEWAY_CALL_COMPLETED', 'GATEWAY_CALL_ERROR',
        'CLIO_BILL_DEBUG', 'AdvancedMD_DEBUG', 'CLIO_BILL_MARK_SENT_SKIPPED', 'PMS_FEE_MARKED_FIXED',
    ];

    private const ACTORS = [
        'payer'    => 'Customer',
        'staff'    => 'Staff',
        'merchant' => 'Your team',
        'admin'    => 'SimpHub support',
    ];

    private string $pms = 'the PMS';

    /** @var array<string, Transaction> */
    private array $transactions = [];

    /**
     * Newest first.
     *
     * `last_at` is set when several back-to-back page visits are folded into one entry.
     *
     * @return list<array{at: string, last_at: ?string, title: string, detail: ?string, tone: string, who: ?string}>
     */
    public function forInvoice(Invoice $invoice): array
    {
        $this->pms = self::pmsLabel((string) $invoice->pms_source);

        $sessionIds = PaymentSession::query()->where('invoice_id', $invoice->id)->pluck('id')->all();

        $this->transactions = Transaction::query()
            ->where('invoice_id', $invoice->id)
            ->get(['id', 'amount_cents', 'gateway', 'status'])
            ->keyBy('id')
            ->all();

        $rows = AuditLog::query()
            ->where(function ($query) use ($invoice, $sessionIds): void {
                $query->where(fn ($q) => $q->where('entity_type', 'invoice')->where('entity_id', $invoice->id));

                if ($sessionIds !== []) {
                    $query->orWhere(fn ($q) => $q->where('entity_type', 'payment_session')->whereIn('entity_id', $sessionIds));
                }

                if ($this->transactions !== []) {
                    $query->orWhere(fn ($q) => $q->where('entity_type', 'transaction')->whereIn('entity_id', array_keys($this->transactions)));
                }
            })
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get(['id', 'event_type', 'entity_type', 'entity_id', 'actor_type', 'payload', 'created_at']);

        $entries = [];
        $visits  = [];

        foreach ($rows as $row) {
            $payload = (array) $row->payload;

            if ($row->entity_type === 'transaction') {
                $payload['transaction_id'] ??= $row->entity_id;
            }
            $at      = Carbon::parse($row->created_at);

            if (in_array($row->event_type, self::VISIT_EVENTS, true) || str_starts_with($row->event_type, 'CHECKOUT_')) {
                $key = is_string($payload['visit_id'] ?? null) ? 'visit:'.$payload['visit_id'] : 'row:'.$row->id;

                if (! isset($visits[$key])) {
                    $visits[$key] = count($entries);
                    $entries[]    = ['visit' => true, 'at' => $at, 'actor' => $row->actor_type, 'steps' => [], 'tone' => 'neutral', 'opened' => false];
                }

                $this->addVisitStep($entries[$visits[$key]], $row->event_type, $payload, $row->actor_type);

                continue;
            }

            $entry = $this->describe($row->event_type, $payload);

            if ($entry !== null) {
                $entries[] = $entry + ['at' => $at, 'who' => self::ACTORS[$row->actor_type] ?? null];
            }
        }

        return array_reverse($this->finish($entries));
    }

    /**
     * @return array{title: string, detail?: ?string, tone: string}|null
     */
    private function describe(string $event, array $payload): ?array
    {
        if (in_array($event, self::HIDDEN_EVENTS, true)) {
            return null;
        }

        $pms     = $this->pms;
        $emails  = $this->emails($payload['recipient_emails'] ?? null);
        $txn     = $this->transactions[(string) ($payload['transaction_id'] ?? '')] ?? null;
        $txnText = $txn ? self::money((int) $txn->amount_cents) : null;

        return match ($event) {
            'INVOICE_RECEIVED'            => ['title' => "Invoice received from {$pms}", 'tone' => 'info'],
            'SESSION_CREATED'             => ['title' => 'Payment link created', 'tone' => 'neutral'],
            'PAYMENT_LINK_SENT'           => ['title' => 'Payment link emailed', 'detail' => $emails ? "To {$emails}" : null, 'tone' => 'info'],
            'PAYMENT_LINK_RESENT'         => ['title' => 'Payment link resent', 'detail' => $emails ? "To {$emails}" : null, 'tone' => 'info'],
            'payment_link.resent_on_invoice_update' => ['title' => "Updated invoice emailed after a change in {$pms}", 'detail' => $emails ? "To {$emails}" : null, 'tone' => 'info'],
            'payment_link.initial_send_failed',
            'payment_link.resend_failed'  => self::reachedSome($event, $payload)
                ? ['title' => 'Payment link email reached only some recipients', 'detail' => $this->failedEmails($payload), 'tone' => 'warning']
                : ['title' => 'Payment link email could not be delivered', 'detail' => $this->failedEmails($payload), 'tone' => 'danger'],
            'PAYMENT_LINK_HELD'           => ['title' => 'Payment link held, not emailed', 'detail' => self::reason($payload['reason'] ?? null), 'tone' => 'warning'],
            'PAYMENT_LINK_SKIPPED'        => ['title' => 'Payment link not emailed', 'detail' => self::reason($payload['reason'] ?? null), 'tone' => 'warning'],
            'payment_link.reopened'       => ['title' => 'Payment link reopened', 'detail' => "A balance was due again in {$pms}. The customer was not emailed.", 'tone' => 'info'],
            'payment_link.marked_paid_via_qb_payment' => ['title' => 'Payment link closed: paid in QuickBooks', 'tone' => 'success'],
            'payment_link.paid'           => ['title' => "Payment link closed: paid in {$pms}", 'tone' => 'success'],
            'payment_link.voided'         => ['title' => "Payment link closed: invoice voided in {$pms}", 'tone' => 'neutral'],
            'payment_link.disabled'       => ['title' => "Payment link closed: invoice removed from {$pms}", 'tone' => 'neutral'],

            'payment_reminder_sent'       => ['title' => 'Reminder'.self::number($payload).' emailed', 'tone' => 'info'],
            'payment_reminder_skipped'    => ['title' => 'Reminder'.self::number($payload).' skipped', 'detail' => self::reason($payload['reason'] ?? null), 'tone' => 'warning'],
            'payment_reminder_failed'     => ['title' => 'Reminder'.self::number($payload).' could not be sent', 'tone' => 'danger'],
            'payment_reminder_stopped'    => ['title' => 'Reminders stopped', 'detail' => self::reason($payload['reason'] ?? null), 'tone' => 'neutral'],
            'payment_reminders_paused'    => ['title' => 'Reminders paused', 'tone' => 'neutral'],
            'payment_reminders_resumed'   => ['title' => 'Reminders resumed', 'detail' => 'A reminder was sent and the schedule restarted.', 'tone' => 'info'],
            'payment_reminders_restarted_on_resend' => ['title' => 'Reminder schedule restarted after a resend', 'tone' => 'neutral'],

            'PAYMENT_DECLINED'            => ['title' => 'Payment declined', 'detail' => self::short($payload['message'] ?? null), 'tone' => 'danger'],
            'GATEWAY_MISCONFIGURED'       => ['title' => 'Customer could not pay: no payment method is set up for this invoice', 'tone' => 'danger'],
            'PAYMENT_APPROVED'            => ['title' => 'Payment approved', 'detail' => $txnText ?? null, 'tone' => 'success'],
            'ACH_PAYMENT_SETTLED'         => ['title' => 'Bank payment settled', 'tone' => 'success'],
            'ACH_PAYMENT_RETURNED'        => ['title' => 'Bank payment returned', 'detail' => self::short($payload['return_reason'] ?? null), 'tone' => 'danger'],
            'REFUND_PROCESSED'            => ['title' => 'Refund issued', 'detail' => isset($payload['amount_cents']) ? self::money((int) $payload['amount_cents']) : null, 'tone' => 'neutral'],
            'VOID_ATTEMPTED'              => ! empty($payload['approved'])
                ? ['title' => 'Payment voided', 'tone' => 'neutral']
                : ['title' => 'Void attempt failed', 'detail' => self::short($payload['message'] ?? null), 'tone' => 'danger'],
            'VOID_FAILED'                 => ['title' => 'Void attempt failed', 'tone' => 'danger'],

            'INVOICE_MARKED_PAID_VIA_PMS' => ['title' => "Invoice marked paid in {$pms}", 'tone' => 'success'],
            'INVOICE_PAID_WITH_OUTSTANDING' => ['title' => 'Paid together with another invoice', 'tone' => 'success'],
            'PMS_PAYMENT_RECORDED'        => ['title' => "Payment recorded in {$pms}", 'detail' => $txnText, 'tone' => 'success'],
            'PMS_PAYMENT_RECORD_FAILED'   => ['title' => "Payment could not be recorded in {$pms}", 'detail' => 'The customer paid, but the payment still needs to be recorded in '.$pms.'.', 'tone' => 'danger'],
            'PMS_PAYMENT_RECORD_SKIPPED'  => ['title' => "Payment not recorded in {$pms}", 'detail' => "Automatic recording in {$pms} is switched off, so it needs to be recorded there by hand.", 'tone' => 'warning'],
            'PMS_FEE_NOT_SPLIT'           => ['title' => "Processing fee not recorded as a separate line in {$pms}", 'tone' => 'warning'],
            'ZOHO_BOOKS_PAYMENT_RECORDED' => ['title' => 'Payment recorded in Zoho Books', 'tone' => 'success'],
            'ZOHO_BOOKS_SYNC_FAILED'      => ['title' => 'Payment could not be recorded in Zoho Books', 'tone' => 'danger'],
            'CLIO_BALANCE_CHANGED'        => [
                'title'  => 'Amount updated from Clio',
                'detail' => isset($payload['previous_cents'], $payload['live_cents'])
                    ? self::money((int) $payload['previous_cents']).' → '.self::money((int) $payload['live_cents'])
                    : null,
                'tone'   => 'info',
            ],
            'CLIO_BILL_MARKED_SENT'       => ['title' => 'Bill marked as sent in Clio', 'tone' => 'neutral'],
            'CLIO_BILL_MARK_SENT_FAILED'  => ['title' => 'Bill could not be marked as sent in Clio', 'tone' => 'warning'],
            'LAWCUS_MATTER_ACTIVITY_LOGGED' => ['title' => 'Note added to the Lawcus matter', 'detail' => self::short($payload['subject'] ?? null), 'tone' => 'neutral'],
            'LAWCUS_MATTER_ACTIVITY_FAILED' => ['title' => 'Note could not be added to the Lawcus matter', 'tone' => 'warning'],
            'LAWCUS_COVERED_INVOICE_ALREADY_PAID' => ['title' => 'Already paid in Lawcus, so the payment was not applied again', 'tone' => 'warning'],

            default => ['title' => Str::ucfirst(str_replace(['payment_link.', '_', '.'], ['Payment link ', ' ', ' '], Str::lower($event))), 'tone' => 'neutral'],
        };
    }

    private function addVisitStep(array &$visit, string $event, array $payload, string $actor): void
    {
        if ($actor === 'staff') {
            $visit['actor'] = 'staff';
        }

        $step = match ($event) {
            'CHECKOUT_PAGE_OPENED'             => null,
            'CHECKOUT_LOAD_FAILED'             => ['Page could not load'.self::suffix($payload['message'] ?? null), 'danger'],
            'CHECKOUT_PAYMENT_FORM_UNAVAILABLE',
            'PAYA_FORM_UNAVAILABLE'            => ['Payment form could not load', 'danger'],
            'CHECKOUT_CASH_OPTION_SELECTED'    => ['Chose to pay by cash or check', null],
            'CHECKOUT_SUBMIT_CLICKED',
            'PAYMENT_SUBMITTED'                => ['Submitted a payment', null],
            'CHECKOUT_CARD_TOKENIZE_FAILED'    => ['Card details were rejected'.self::suffix($payload['message'] ?? null), 'danger'],
            'BANK_DETAILS_REJECTED'            => ['Bank details were rejected', 'danger'],
            'CHECKOUT_PAGE_ERROR'              => ['The page showed an error', 'danger'],
            'PAYMENT_ATTEMPT_FAILED'           => match ($payload['stage'] ?? null) {
                'validation'   => ['Payment form had missing or invalid fields', 'danger'],
                'server_error' => ['Payment failed because of a system error', 'danger'],
                default        => ['Payment failed'.self::suffix($payload['message'] ?? null), 'danger'],
            },
            'PAYMENT_ATTEMPT_SUCCEEDED'        => ['Paid '.self::money((int) ($payload['amount_cents'] ?? 0)), 'success'],
            default                            => null,
        };

        if ($event === 'CHECKOUT_PAGE_OPENED') {
            $visit['opened'] = true;
        }

        if ($step === null || in_array($step[0], $visit['steps'], true)) {
            return;
        }

        $visit['steps'][] = $step[0];

        if ($step[1] === 'success' || ($step[1] === 'danger' && $visit['tone'] !== 'success')) {
            $visit['tone'] = $step[1];
        }
    }

    /**
     * Turns visit groups into entries, and folds back-to-back plain visits by the
     * same kind of viewer into one line ("opened the payment page 3 times").
     */
    private function finish(array $entries): array
    {
        $out = [];

        foreach ($entries as $entry) {
            if (empty($entry['visit'])) {
                $out[] = self::shape($entry);
                continue;
            }

            $who   = $entry['actor'] === 'staff' ? 'Staff' : 'Customer';
            $plain = $entry['steps'] === [];
            $last  = $out === [] ? null : $out[count($out) - 1];

            if ($plain && $last !== null && ($last['_plain_visit'] ?? null) === $who) {
                $count = $last['_count'] + 1;
                $out[count($out) - 1] = array_merge($last, [
                    '_count'  => $count,
                    'title'   => "{$who} opened the payment page {$count} times",
                    'last_at' => $entry['at']->toIso8601String(),
                ]);
                continue;
            }

            $title = $who === 'Staff' ? 'Staff opened the payment page' : 'Customer opened the payment page';

            if (! $entry['opened'] && ! $plain) {
                $title = $who === 'Staff' ? 'Staff used the payment page' : 'Customer used the payment page';
            }

            $out[] = self::shape([
                'at'     => $entry['at'],
                'title'  => $title,
                'detail' => $plain ? null : implode(' · ', $entry['steps']),
                'tone'   => $entry['tone'],
                'who'    => $who,
            ]) + ($plain ? ['_plain_visit' => $who, '_count' => 1] : []);
        }

        return array_map(function (array $entry): array {
            unset($entry['_plain_visit'], $entry['_count']);

            return $entry;
        }, $out);
    }

    private static function shape(array $entry): array
    {
        return [
            'at'      => $entry['at']->toIso8601String(),
            'last_at' => null,
            'title'   => $entry['title'],
            'detail'  => $entry['detail'] ?? null,
            'tone'    => $entry['tone'],
            'who'     => $entry['who'] ?? null,
        ];
    }

    private function emails(mixed $emails): ?string
    {
        $list = array_values(array_filter(array_map('strval', (array) $emails)));

        return $list === [] ? null : implode(', ', $list);
    }

    private function failedEmails(array $payload): ?string
    {
        $reasons = (array) ($payload['failure_reasons'] ?? []);
        $parts   = [];

        foreach ((array) ($payload['failed_emails'] ?? []) as $email) {
            $reason  = $reasons[$email] ?? null;
            $parts[] = $email.(is_string($reason) && $reason !== '' ? ' ('.Str::limit($reason, 120).')' : '');
        }

        return $parts === [] ? null : 'Not delivered to '.implode(', ', $parts);
    }

    /** Same rule as the invoice list: a send counts as failed only when it reached nobody. */
    private static function reachedSome(string $event, array $payload): bool
    {
        if ($event === 'payment_link.initial_send_failed') {
            return ! empty($payload['sent_emails']);
        }

        return count((array) ($payload['failed_emails'] ?? [])) < count((array) ($payload['attempted_emails'] ?? []));
    }

    private static function number(array $payload): string
    {
        return isset($payload['reminder_number']) ? ' #'.(int) $payload['reminder_number'] : '';
    }

    private static function reason(mixed $reason): ?string
    {
        if (! is_string($reason) || $reason === '') {
            return null;
        }

        return match ($reason) {
            'trust_links_disabled'      => 'Trust request links are set to not send automatically.',
            'trust_balance_unconfirmed' => 'The trust balance could not be confirmed.',
            'nothing_due', 'paid'       => 'Nothing is due on this invoice.',
            'gone', 'deleted'           => 'The invoice was removed from the PMS.',
            'voided'                    => 'The invoice was voided.',
            'no_customer_email'         => 'The customer has no email address.',
            default                     => Str::ucfirst(str_replace('_', ' ', Str::limit($reason, 120))).'.',
        };
    }

    private static function short(mixed $text): ?string
    {
        return is_string($text) && trim($text) !== '' ? Str::limit(trim($text), 160) : null;
    }

    private static function suffix(mixed $text): string
    {
        $text = self::short($text);

        return $text === null ? '' : ": {$text}";
    }

    private static function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }

    public static function pmsLabel(string $pmsSource): string
    {
        return match (strtolower($pmsSource)) {
            'quickbooks' => 'QuickBooks',
            'clio'       => 'Clio',
            'zoho'       => 'Zoho Books',
            'zohocrm'    => 'Zoho CRM',
            'lawcus'     => 'Lawcus',
            'wave'       => 'Wave',
            'advancedmd' => 'AdvancedMD',
            'mindbody'   => 'Mindbody',
            ''           => 'the PMS',
            default      => ucfirst($pmsSource),
        };
    }
}
