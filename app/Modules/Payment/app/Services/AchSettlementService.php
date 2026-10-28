<?php

namespace Modules\Payment\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;
use Modules\Outbound\DTOs\AchStatus;
use Modules\Outbound\Services\PayaAchStatusService;
use Modules\Routing\Models\RoutingRule;

/**
 * Follows ACH payments from submitted to settled or returned. Paya (GETI) has no webhooks,
 * so PollAchSettlementsJob asks it once a day about every payment still PENDING:
 *  - SETTLED  → the firm gets its "You got paid" email (held back at submit).
 *  - RETURNED → the payment is marked RETURNED, the invoice is unpaid again, and the firm
 *               is told to reverse the payment already recorded in its PMS (Lawcus, ...).
 */
class AchSettlementService
{
    /** Days after which a payment that's still PENDING stops being polled. */
    private const MAX_POLL_DAYS = 30;

    public function __construct(
        private readonly PayaAchStatusService $paya,
        private readonly MerchantNotificationService $notifications,
    ) {}

    /**
     * The firm's notification waits for settlement only when we can actually find out
     * about it — otherwise it goes out at submit, as before.
     */
    public function holdsMerchantNotification(Transaction $transaction): bool
    {
        return $transaction->settlement_status === AchStatus::PENDING
            && strtolower((string) $transaction->gateway) === 'paya'
            && $this->paya->isConfigured();
    }

    /** @return array{checked: int, settled: int, returned: int, failed: int} */
    public function pollPending(): array
    {
        $counts = ['checked' => 0, 'settled' => 0, 'returned' => 0, 'failed' => 0];

        if (! $this->paya->isConfigured()) {
            Log::info('AchSettlementService: Paya status lookup not configured; skipping ACH settlement poll.');

            return $counts;
        }

        Transaction::query()
            ->with('invoice')
            ->where('gateway', 'paya')
            ->where('status', 'CAPTURED')
            ->where('settlement_status', AchStatus::PENDING)
            ->where('created_at', '>=', now()->subDays(self::MAX_POLL_DAYS))
            ->orderBy('created_at')
            ->each(function (Transaction $transaction) use (&$counts): void {
                $counts['checked']++;

                try {
                    $status = $this->paya->lookup((string) $transaction->gateway_txn_id, $this->credentialsFor($transaction));
                } catch (\Throwable $e) {
                    $counts['failed']++;
                    Log::warning('AchSettlementService: Paya status lookup failed', [
                        'transaction_id' => $transaction->id,
                        'gateway_txn_id' => $transaction->gateway_txn_id,
                        'error'          => $e->getMessage(),
                    ]);

                    return;
                }

                $transaction->forceFill(['settlement_checked_at' => now()])->save();

                if ($status->status === AchStatus::SETTLED) {
                    $this->markSettled($transaction);
                    $counts['settled']++;
                } elseif ($status->status === AchStatus::RETURNED) {
                    $this->markReturned($transaction, $status);
                    $counts['returned']++;
                }
            });

        return $counts;
    }

    public function markSettled(Transaction $transaction): void
    {
        $transaction->forceFill([
            'settlement_status' => AchStatus::SETTLED,
            'settled_at'        => now(),
        ])->save();

        AuditLogger::log('ACH_PAYMENT_SETTLED', 'transaction', $transaction->id, [
            'gateway_txn_id' => $transaction->gateway_txn_id,
            'fund_type'      => $transaction->fund_type,
        ]);

        $this->notifications->sendPaymentNotification($transaction);
    }

    public function markReturned(Transaction $transaction, AchStatus $status): void
    {
        DB::transaction(function () use ($transaction, $status): void {
            $transaction->forceFill([
                'status'            => 'RETURNED',
                'settlement_status' => AchStatus::RETURNED,
                'returned_at'       => now(),
                'return_code'       => $status->returnCode,
                'return_reason'     => $status->returnReason !== null ? mb_substr($status->returnReason, 0, 255) : null,
            ])->save();

            // The money never arrived: the invoice is open again in SimpHub. The PMS still
            // shows it paid until the firm reverses the payment there.
            $transaction->invoice?->forceFill(['status' => 'PENDING'])->save();

            AuditLogger::log('ACH_PAYMENT_RETURNED', 'transaction', $transaction->id, [
                'gateway_txn_id' => $transaction->gateway_txn_id,
                'fund_type'      => $transaction->fund_type,
                'return_code'    => $status->returnCode,
                'return_reason'  => $status->returnReason,
            ]);
        });

        $this->notifications->sendAchReturnedNotice($transaction->fresh('invoice'));
    }

    /**
     * The credentials the payment was submitted with: the routing rule's, overlaid with the
     * client's Gateway Credentials for its fund type (IOLTA for trust) — as at checkout.
     */
    private function credentialsFor(Transaction $transaction): array
    {
        $credentials = [];

        try {
            $credentials = (array) (RoutingRule::query()->find($transaction->routing_rule_id)?->mid_credentials ?? []);
        } catch (DecryptException) {
            // Fall through to the client's own credentials.
        }

        $pmsClientId = $transaction->invoice?->pms_client_id;
        $client      = $pmsClientId ? Client::query()->where('pms_client_id', $pmsClientId)->first() : null;

        if ($client) {
            try {
                $credentials = array_merge($credentials, $client->gatewayCredentialsFor('paya', $transaction->fund_type));
            } catch (DecryptException) {
                // Routing rule credentials only.
            }
        }

        return $credentials;
    }
}
