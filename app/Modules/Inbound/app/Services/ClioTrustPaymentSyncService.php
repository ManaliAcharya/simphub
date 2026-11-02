<?php

namespace Modules\Inbound\Services;

use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClioConnection;
use RuntimeException;
use Throwable;

/**
 * Records a captured Trust Request payment in Clio as a deposit into the firm's trust bank
 * account (POST /bank_transactions), on the client's — and where known the matter's — trust
 * ledger. The regular bill payment endpoint is not used: it can't name the account, and
 * trust money must provably land in trust — commingling it with operating funds is an
 * IOLTA violation.
 */
class ClioTrustPaymentSyncService
{
    public function __construct(
        private readonly ClioApiClient $api,
        private readonly ClioOAuthService $oauth,
    ) {}

    /**
     * @param  float  $amount  the invoice portion of the charge (no card fee) — see SyncInvoicePaidListener::invoicePortion()
     * @return bool true when the payment is (now or already) recorded in Clio
     */
    public function sync(Transaction $transaction, Invoice $invoice, float $amount): bool
    {
        try {
            $client    = Client::query()->where('pms_client_id', $invoice->pms_client_id)->firstOrFail();
            $accountId = trim((string) $client->clio_trust_bank_account_id);

            // Never falls back to the default (operating) account.
            if ($accountId === '') {
                throw new RuntimeException('No Clio trust bank account configured for this client; trust payment not recorded.');
            }

            $clioClientId = trim((string) $invoice->external_client_id);

            if (! ctype_digit($clioClientId)) {
                throw new RuntimeException("Clio contact id [{$clioClientId}] is not usable; trust payment not recorded.");
            }

            $connection = ClioConnection::query()
                ->where('provider', 'clio')
                ->where('pms_client_id', $invoice->pms_client_id)
                ->latest('created_at')
                ->first();

            if (! $connection) {
                throw new RuntimeException('Clio is not connected for this client.');
            }

            $connection = $this->oauth->ensureValidAccessToken($connection);

            $reference = $this->reference($transaction);
            $deposits  = self::allocate(
                $amount,
                $this->api->fetchTrustLineItems($connection, (string) $invoice->external_invoice_id),
                $invoice->external_matter_id,
            );

            if ($deposits === []) {
                throw new RuntimeException('Trust payment amount is zero; nothing to record.');
            }

            // Retries (listener re-run) must not deposit the same money twice: each deposit's
            // description carries the reference, so ones already in Clio are skipped.
            $since    = ($transaction->created_at ?? now())->copy()->subDay()->utc()->toIso8601String();
            $existing = collect($this->api->fetchBankTransactions($connection, $accountId, $clioClientId, $since))
                ->map(fn ($row) => is_array($row) ? (string) ($row['description'] ?? '') : '')
                ->all();

            $recorded = [];

            foreach ($deposits as $deposit) {
                $description = self::description($reference, $invoice, $deposit['matter_id']);

                if (in_array($description, $existing, true)) {
                    continue;
                }

                $this->api->recordBankTransaction($connection, array_filter([
                    'amount'       => $deposit['amount'],
                    'bank_account' => ['id' => (int) $accountId],
                    'client'       => ['id' => (int) $clioClientId],
                    'matter'       => $deposit['matter_id'] !== null ? ['id' => (int) $deposit['matter_id']] : null,
                    'date'         => ($transaction->created_at ?? now())->toDateString(),
                    'description'  => $description,
                ], fn ($value) => $value !== null));

                $recorded[] = $deposit;
            }

            $invoice->forceFill(['pms_sync_status' => 'SYNCED'])->save();

            AuditLogger::log('PMS_PAYMENT_RECORDED', 'invoice', $invoice->id, [
                'pms_source'          => 'clio',
                'fund_type'           => 'TRUST',
                'transaction_id'      => $transaction->id,
                'gateway'             => $transaction->gateway,
                'gateway_txn_id'      => $transaction->gateway_txn_id,
                'external_invoice_id' => $invoice->external_invoice_id,
                'bank_account_id'     => $accountId,
                'amount'              => $amount,
                'deposits'            => $deposits,
                'already_recorded'    => count($deposits) - count($recorded),
            ]);

            return true;
        } catch (Throwable $exception) {
            $invoice->forceFill(['pms_sync_status' => 'FAILED'])->save();

            AuditLogger::log('PMS_PAYMENT_RECORD_FAILED', 'invoice', $invoice->id, [
                'pms_source'     => 'clio',
                'fund_type'      => 'TRUST',
                'transaction_id' => $transaction->id,
                'gateway'        => $transaction->gateway,
                'error'          => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Splits the paid amount across the Trust Request's matters in proportion to what was
     * requested for each, to the cent (the last deposit takes the rounding remainder). With
     * no matter-level line items the whole amount goes to the bill's single matter, or to
     * the client's trust ledger when it has none.
     *
     * @return list<array{matter_id: ?string, amount: float}>
     */
    public static function allocate(float $amount, array $trustLineItems, ?string $fallbackMatterId): array
    {
        $amountCents = (int) round($amount * 100);

        if ($amountCents <= 0) {
            return [];
        }

        $byMatter = [];

        foreach ($trustLineItems as $item) {
            $cents = is_array($item) ? (int) round(((float) ($item['total'] ?? 0)) * 100) : 0;

            if ($cents <= 0) {
                continue;
            }

            // '' collects line items with no matter (client-level trust).
            $matterId            = is_array($item['matter'] ?? null) ? (string) ($item['matter']['id'] ?? '') : '';
            $byMatter[$matterId] = ($byMatter[$matterId] ?? 0) + $cents;
        }

        $requestedCents = array_sum($byMatter);

        if ($requestedCents <= 0) {
            $matterId = trim((string) $fallbackMatterId);

            return [['matter_id' => $matterId !== '' ? $matterId : null, 'amount' => round($amountCents / 100, 2)]];
        }

        $deposits  = [];
        $allocated = 0;
        $keys      = array_keys($byMatter);

        foreach ($keys as $index => $key) {
            $cents = $index === count($keys) - 1
                ? $amountCents - $allocated
                : intdiv($amountCents * $byMatter[$key], $requestedCents);

            $allocated += $cents;

            if ($cents > 0) {
                $deposits[] = ['matter_id' => $key !== '' ? (string) $key : null, 'amount' => round($cents / 100, 2)];
            }
        }

        return $deposits;
    }

    /**
     * The deposit's description in Clio — shows staff where the money came from and doubles
     * as the duplicate-detection key, so it must be the same on every retry.
     */
    public static function description(string $reference, Invoice $invoice, ?string $matterId): string
    {
        $label = $invoice->invoice_number ? "Trust Request #{$invoice->invoice_number}" : "Trust Request {$invoice->external_invoice_id}";

        return "{$reference} · {$label}".($matterId !== null ? " · matter {$matterId}" : '');
    }

    private function reference(Transaction $transaction): string
    {
        return 'Payment Middleware '.strtoupper((string) $transaction->gateway)
            .' txn '.($transaction->gateway_txn_id ?: $transaction->id);
    }
}
