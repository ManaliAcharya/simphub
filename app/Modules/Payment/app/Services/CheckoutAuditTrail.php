<?php

namespace Modules\Payment\Services;

use Illuminate\Support\Str;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\PaymentSession;

/**
 * Audit trail of everything a payer does on the hosted checkout page, so a
 * "I tried to pay and it failed" report can be traced step by step.
 *
 * Every row carries the page visit id the checkout page generates on load, so
 * the server-side steps and the steps the browser reports line up per visit.
 */
class CheckoutAuditTrail
{
    /**
     * Steps the checkout page reports from the browser — things the server never
     * sees on its own (option picked, card form rejected, page closed). Anything
     * not listed here is dropped.
     */
    public const BROWSER_EVENTS = [
        'option_selected',
        'cash_option_selected',
        'cash_instructions_shown',
        'payment_form_ready',
        'payment_form_unavailable',
        'bank_account_secured',
        'submit_clicked',
        'submit_blocked',
        'card_tokenized',
        'card_tokenize_failed',
        'request_failed',
        'payment_failed_shown',
        'confirmation_shown',
        'redirected_to_cancel_url',
        'page_error',
        'page_closed',
    ];

    /**
     * Detail keys a browser event may carry. Card and bank numbers never leave
     * the gateway fields, and nothing else is accepted from the page.
     */
    private const BROWSER_DETAIL_KEYS = [
        'gateway', 'payment_method', 'routing_rule_id', 'mode', 'reason',
        'status', 'message', 'step', 'last_step',
    ];

    public function record(PaymentSession $session, string $eventType, array $payload = []): void
    {
        AuditLogger::log($eventType, 'payment_session', (string) $session->id, array_merge([
            'invoice_id'     => $session->invoice_id,
            'session_status' => $session->status,
            'link_status'    => $session->link_status,
            'visit_id'       => $this->visitId(),
            'user_agent'     => Str::limit((string) request()?->userAgent(), 255, ''),
        ], $payload), 'payer');
    }

    public function recordBrowserEvent(PaymentSession $session, string $event, array $detail): bool
    {
        if (! in_array($event, self::BROWSER_EVENTS, true)) {
            return false;
        }

        $detail = collect($detail)
            ->only(self::BROWSER_DETAIL_KEYS)
            ->filter(fn ($value) => is_scalar($value))
            ->map(fn ($value) => Str::limit((string) $value, 255, ''))
            ->all();

        $this->record($session, 'CHECKOUT_'.strtoupper($event), $detail + ['source' => 'browser']);

        return true;
    }

    private function visitId(): ?string
    {
        $request = request();
        $visitId = (string) ($request?->header('X-Checkout-Visit') ?: $request?->input('visit_id', ''));

        return Str::isUuid($visitId) ? $visitId : null;
    }
}
