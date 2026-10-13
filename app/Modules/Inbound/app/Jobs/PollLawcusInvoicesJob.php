<?php

namespace Modules\Inbound\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\LawcusConnection;
use Modules\Inbound\Services\LawcusApiClient;
use Modules\Inbound\Services\LawcusInvoiceIngestionService;
use Modules\Inbound\Services\LawcusOAuthService;
use Throwable;

/**
 * Lawcus webhooks don't work with pasted API tokens, so new invoices are found by
 * polling the Accounts Receivable report (sent, unpaid invoices) and handing each one
 * we haven't seen to LawcusInvoiceIngestionService — the same path a webhook would
 * take, which creates the payment session and emails the payment link once.
 */
class PollLawcusInvoicesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * Auth failures (revoked/deleted token) are counted by LawcusOAuthService; past this
     * many the connection is skipped until someone replaces the token, which resets it.
     */
    private const MAX_CONSECUTIVE_FAILURES = 3;

    public function handle(
        LawcusApiClient $api,
        LawcusOAuthService $oauth,
        LawcusInvoiceIngestionService $ingestion,
    ): void {
        // Only the latest connection per client is live after a reconnect.
        $connections = LawcusConnection::query()
            ->where('provider', 'lawcus')
            ->orderByDesc('created_at')
            ->get()
            ->unique('pms_client_id')
            ->values();

        foreach ($connections as $connection) {
            $this->pollConnection($connection, $api, $oauth, $ingestion);
        }
    }

    private function pollConnection(
        LawcusConnection $connection,
        LawcusApiClient $api,
        LawcusOAuthService $oauth,
        LawcusInvoiceIngestionService $ingestion,
    ): void {
        if ((int) $connection->consecutive_failures >= self::MAX_CONSECUTIVE_FAILURES) {
            Log::warning('Lawcus invoice poll skipped — connection has repeated auth failures', [
                'pms_client_id' => $connection->pms_client_id,
                'last_error'    => $connection->last_error,
            ]);

            return;
        }

        try {
            $outstanding = $api->fetchOutstandingInvoices($connection);
            $oauth->recordApiSuccess($connection);
        } catch (Throwable $e) {
            $oauth->recordApiFailure($connection, $e);

            Log::error('Lawcus invoice poll failed', [
                'pms_client_id' => $connection->pms_client_id,
                'error'         => $e->getMessage(),
            ]);

            return;
        }

        $since = $this->pollingBaseline($connection);

        // Only invoices issued since the firm connected — otherwise connecting a firm
        // would email payment links for its entire existing receivables backlog.
        $candidates = collect($outstanding)->filter(function (array $invoice) use ($since): bool {
            $issued = $this->parseDate($invoice['issue_date'] ?? null);

            return $issued !== null
                && $issued->greaterThanOrEqualTo($since)
                && (float) ($invoice['amount_due'] ?? 0) > 0;
        });

        if ($candidates->isEmpty()) {
            return;
        }

        $alreadyIngested = Invoice::query()
            ->where('pms_source', 'lawcus')
            ->where('pms_client_id', $connection->pms_client_id)
            ->whereIn('external_invoice_id', $candidates->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->pluck('external_invoice_id')
            ->all();

        $new = $candidates->reject(fn (array $invoice) => in_array((string) $invoice['id'], $alreadyIngested, true));

        foreach ($new as $invoice) {
            try {
                $result = $ingestion->ingest((string) $invoice['id'], [
                    'pms_client_id' => $connection->pms_client_id,
                    'event_name'    => 'poll.invoice.outstanding',
                ]);

                Log::info('Lawcus invoice poll ingested invoice', [
                    'pms_client_id' => $connection->pms_client_id,
                    'invoice_id'    => $invoice['id'],
                    'number'        => $invoice['number'] ?? null,
                    'emails_sent'   => $result['emails_sent'] ?? 0,
                ]);
            } catch (Throwable $e) {
                // Not recorded as ingested, so the next run retries it.
                Log::error('Lawcus invoice poll ingestion failed', [
                    'pms_client_id' => $connection->pms_client_id,
                    'invoice_id'    => $invoice['id'],
                    'error'         => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Start of the day the firm connected. Compared at day granularity because Lawcus
     * issue dates carry no timezone.
     */
    private function pollingBaseline(LawcusConnection $connection): CarbonImmutable
    {
        return CarbonImmutable::parse($connection->created_at)->startOfDay();
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
