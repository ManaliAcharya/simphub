<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;

class UnsyncedFeesCommand extends Command
{
    protected $signature = 'fees:unsynced
        {--client= : Only this client (name or pms_client_id)}
        {--backfill : Fill in fee_sync_status for payments made before it was tracked, from the audit log}
        {--mark-fixed=* : Transaction id or gateway transaction id whose fee was corrected in the PMS}';

    protected $description = 'List payments whose fee was not split out to a surcharge income account in the PMS (NOT_SPLIT / NOT_RECORDED), so they can be corrected.';

    public function handle(): int
    {
        if ($this->option('backfill')) {
            $this->backfill();
        }

        if ($ids = (array) $this->option('mark-fixed')) {
            $this->markFixed($ids);

            return self::SUCCESS;
        }

        $rows = $this->scoped(Transaction::query())
            ->whereIn('transactions.fee_sync_status', [Transaction::FEE_NOT_SPLIT, Transaction::FEE_NOT_RECORDED])
            ->join('invoices', 'invoices.id', '=', 'transactions.invoice_id')
            ->leftJoin('clients', 'clients.pms_client_id', '=', 'invoices.pms_client_id')
            ->orderBy('transactions.created_at')
            ->get([
                'transactions.created_at', 'clients.client_name', 'invoices.pms_source', 'invoices.invoice_number',
                'transactions.amount_cents', 'transactions.fee_cents', 'transactions.fee_sync_status',
                'transactions.gateway_txn_id', 'transactions.qb_payment_id',
            ]);

        if ($rows->isEmpty()) {
            $this->components->info('No fees waiting to be fixed.');

            return self::SUCCESS;
        }

        $this->table(
            ['Paid', 'Client', 'PMS', 'Invoice', 'Charged', 'Fee', 'Fee status', 'Gateway txn', 'QB payment'],
            $rows->map(fn ($r) => [
                substr((string) $r->created_at, 0, 16),
                $r->client_name,
                $r->pms_source,
                $r->invoice_number,
                number_format($r->amount_cents / 100, 2),
                number_format($r->fee_cents / 100, 2),
                $r->fee_sync_status,
                $r->gateway_txn_id,
                $r->qb_payment_id ?? '-',
            ])->all(),
        );

        $this->line(sprintf(
            '%d payment(s), $%s in fees. NOT_SPLIT: the fee is in the invoice payment (in QuickBooks, a customer credit). NOT_RECORDED: the fee is not in the PMS.',
            $rows->count(),
            number_format($rows->sum('fee_cents') / 100, 2),
        ));
        $this->line('After correcting one in the PMS: php artisan fees:unsynced --mark-fixed=<gateway txn>');

        return self::SUCCESS;
    }

    /**
     * Payments recorded in the PMS before fee_sync_status existed: what happened to the
     * fee is read back from that payment's PMS_PAYMENT_RECORDED audit entry.
     */
    private function backfill(): void
    {
        $candidates = $this->scoped(Transaction::query())
            ->where('transactions.status', 'CAPTURED')
            ->whereNull('transactions.fee_sync_status')
            ->with('invoice')
            ->get()
            ->filter(fn (Transaction $t) => $this->feeCents($t) > 0);

        $filled = 0;

        foreach ($candidates as $transaction) {
            $recorded = DB::table('audit_logs')
                ->where('event_type', 'PMS_PAYMENT_RECORDED')
                ->where('entity_id', $transaction->invoice_id)
                ->where('payload', 'like', '%'.$transaction->id.'%')
                ->latest('created_at')
                ->value('payload');

            if ($recorded === null) {
                continue; // never recorded in the PMS — nothing to classify yet
            }

            $payload = (array) json_decode((string) $recorded, true);
            $pms     = strtolower((string) $transaction->invoice?->pms_source);

            $status = match (true) {
                isset($payload['surcharge_amount']) => ($payload['qb_journalentry_id'] ?? $payload['wave_money_txn_id'] ?? '') !== ''
                    ? Transaction::FEE_SPLIT
                    : Transaction::FEE_NOT_RECORDED,
                $pms === 'lawcus' => Transaction::FEE_NOT_RECORDED,
                default           => Transaction::FEE_NOT_SPLIT,
            };

            $transaction->forceFill(['fee_sync_status' => $status])->save();
            $filled++;
        }

        $this->components->info("Backfilled fee status on {$filled} payment(s).");
    }

    /**
     * @param  array<int, string>  $ids
     */
    private function markFixed(array $ids): void
    {
        foreach ($ids as $id) {
            $transaction = Transaction::query()
                ->where(fn ($q) => $q->where('id', $id)->orWhere('gateway_txn_id', $id))
                ->first();

            if (! $transaction) {
                $this->components->error("{$id}: no such transaction.");

                continue;
            }

            if (! in_array($transaction->fee_sync_status, [Transaction::FEE_NOT_SPLIT, Transaction::FEE_NOT_RECORDED], true)) {
                $this->components->warn("{$id}: fee status is ".($transaction->fee_sync_status ?? 'not set').', nothing to fix.');

                continue;
            }

            $previous = $transaction->fee_sync_status;
            $transaction->forceFill(['fee_sync_status' => Transaction::FEE_FIXED])->save();

            AuditLogger::log('PMS_FEE_MARKED_FIXED', 'invoice', (string) $transaction->invoice_id, [
                'transaction_id'  => $transaction->id,
                'gateway_txn_id'  => $transaction->gateway_txn_id,
                'previous_status' => $previous,
            ]);

            $this->components->info("{$id}: marked FIXED.");
        }
    }

    private function scoped($query)
    {
        if (! $ref = $this->option('client')) {
            return $query;
        }

        $client = Client::query()
            ->where('pms_client_id', $ref)
            ->orWhere('id', $ref)
            ->orWhere('client_name', $ref)
            ->first();

        return $query->whereIn('transactions.invoice_id', DB::table('invoices')
            ->where('pms_client_id', $client?->pms_client_id ?? '__none__')
            ->select('id'));
    }

    private function feeCents(Transaction $transaction): int
    {
        $fee = (int) ($transaction->fee_cents ?? 0);

        if ($fee === 0 && $transaction->invoice && (int) $transaction->amount_cents > (int) $transaction->invoice->amount_cents) {
            $fee = (int) $transaction->amount_cents - (int) $transaction->invoice->amount_cents;
        }

        return $fee;
    }
}
