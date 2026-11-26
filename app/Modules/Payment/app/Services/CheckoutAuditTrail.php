<?php

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\Cache;
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

    /** How long a page visit opened from the invoice list stays marked as a staff visit. */
    private const STAFF_VISIT_TTL_HOURS = 12;

    public function record(PaymentSession $session, string $eventType, array $payload = []): void
    {
        $payload = array_merge([
            'invoice_id'     => $session->invoice_id,
            'session_status' => $session->status,
            'link_status'    => $session->link_status,
            'visit_id'       => $this->visitId(),
            'user_agent'     => Str::limit((string) request()?->userAgent(), 255, ''),
        ], $payload);

        // A visit opened with "Open link" on the invoice list is staff checking the page,
        // not the customer — kept apart so "the customer viewed it" stays true.
        $viewer = $this->staffViewer($payload['visit_id'] ?? null);

        if ($viewer !== null) {
            $payload['viewer'] = $viewer;
        }

        AuditLogger::log($eventType, 'payment_session', (string) $session->id, $payload, $viewer !== null ? 'staff' : 'payer');
    }

    /**
     * Mark a page visit as staff. The visit id is generated on the server for each page
     * load, so a customer can't attach their visit to it; the caller has already checked
     * the signed "Open link" URL.
     */
    public function markStaffVisit(string $visitId, string $viewer): void
    {
        Cache::put(self::staffVisitKey($visitId), Str::limit($viewer, 64, ''), now()->addHours(self::STAFF_VISIT_TTL_HOURS));
    }

    private function staffViewer(?string $visitId): ?string
    {
        if (! is_string($visitId) || ! Str::isUuid($visitId)) {
            return null;
        }

        $viewer = Cache::get(self::staffVisitKey($visitId));

        return is_string($viewer) ? $viewer : null;
    }

    private static function staffVisitKey(string $visitId): string
    {
        return 'checkout_staff_visit:'.$visitId;
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
