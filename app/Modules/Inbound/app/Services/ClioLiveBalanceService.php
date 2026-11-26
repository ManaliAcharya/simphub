<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Models\ClioConnection;
use Modules\Payment\Services\PaymentLinkClosure;
use Throwable;

/**
 * Keeps a Clio payment link at the bill's live balance. Firms apply trust funds or record
 * other payments in Clio after the link goes out, so the payment page reads the bill on
 * every load and again at submit: the amount follows Clio's balance, and a bill that is
 * paid, fully covered, voided or deleted closes its link instead of being charged.
 */
class ClioLiveBalanceService
{
    public const PAYABLE = 'payable';

    public const CLOSED = 'closed';

    public const UNKNOWN = 'unknown';

    public function __construct(
        private readonly ClioApiClient $client,
        private readonly ClioOAuthService $oauth,
    ) {}

    /**
     * @return string PAYABLE (amount_cents now matches Clio), CLOSED (link closed) or
     *                UNKNOWN (Clio couldn't be read; nothing changed)
     */
    public function sync(Invoice $invoice, PaymentSession $session): string
    {
        // A finished payment is never re-priced; other PMSs aren't ours to check.
        if ((string) $invoice->pms_source !== 'clio' || $session->status === 'COMPLETED') {
            return self::PAYABLE;
        }

        try {
            $connection = ClioConnection::query()
                ->where('provider', 'clio')
                ->where('pms_client_id', $invoice->pms_client_id)
                ->first();

            if (! $connection) {
                return self::UNKNOWN;
            }

            $response = $this->client->fetchBillBalance(
                $this->oauth->ensureValidAccessToken($connection),
                (string) $invoice->external_invoice_id,
            );

            if ($response->status() === 404) {
                $this->close($invoice, $session, 'voided', ['state' => 'deleted']);

                return self::CLOSED;
            }

            $response->throw();
        } catch (Throwable $e) {
            Log::warning('Clio live balance check failed', [
                'invoice_id'          => $invoice->id,
                'external_invoice_id' => $invoice->external_invoice_id,
                'error'               => $e->getMessage(),
            ]);

            return self::UNKNOWN;
        }

        $bill = (array) Arr::get((array) $response->json(), 'data', []);

        if ($reason = ClioBillRules::closedReason($bill)) {
            $this->close($invoice, $session, $reason, $bill);

            return self::CLOSED;
        }

        $liveCents = ClioBillRules::toCents(ClioBillRules::amountDue($bill));

        if ($liveCents > 0 && $liveCents !== (int) $invoice->amount_cents) {
            AuditLogger::log('CLIO_BALANCE_CHANGED', 'invoice', $invoice->id, [
                'external_invoice_id' => $invoice->external_invoice_id,
                'previous_cents'      => (int) $invoice->amount_cents,
                'live_cents'          => $liveCents,
                'bill_total'          => $bill['total'] ?? null,
                'bill_paid'           => $bill['paid'] ?? null,
            ]);

            $invoice->forceFill(['amount_cents' => $liveCents])->save();
        }

        return self::PAYABLE;
    }

    private function close(Invoice $invoice, PaymentSession $session, string $reason, array $bill): void
    {
        $state = strtoupper((string) ($bill['state'] ?? ''));

        if ($state !== '') {
            $invoice->forceFill(['status' => $state])->save();
        }

        if (($session->link_status ?? 'active') !== 'active') {
            return;
        }

        PaymentLinkClosure::close($session, $reason === 'voided'
            ? PaymentLinkClosure::VOIDED_IN_PMS
            : PaymentLinkClosure::paidReason($session));

        AuditLogger::log("payment_link.{$reason}", 'payment_session', $session->id, [
            'invoice_id'          => $invoice->id,
            'pms_source'          => 'clio',
            'pms_client_id'       => $invoice->pms_client_id,
            'external_invoice_id' => $invoice->external_invoice_id,
            'clio_state'          => $bill['state'] ?? null,
            'clio_balance'        => $bill['balance'] ?? null,
            'source'              => 'live_balance_check',
        ]);
    }
}
