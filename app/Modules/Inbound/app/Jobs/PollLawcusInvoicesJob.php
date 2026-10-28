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
 * polling the invoice list (POST /v2/invoices) and handing each one we haven't seen to
 * LawcusInvoiceIngestionService — the same path a webhook would take. Saved (or sent),
 * unpaid invoices get a payment session and the link is emailed once; invoices with
 * nothing due (paid from the matter's trust balance) are only recorded.
 * The list is used rather than the AR report because the report leaves out Trust
 * Requests (type TRUST), which route to the firm's IOLTA MID.
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

    /** Statuses never billed even if Lawcus still shows an amount due. */
    private const NOT_PAYABLE_STATUSES = ['PAID', 'DRAFT', 'VOID', 'VOIDED', 'DELETED', 'WRITTEN_OFF', 'CANCELLED'];

    /** Not a real (or still live) invoice, so not recorded at all. */
    private const NOT_RECORDED_STATUSES = ['DRAFT', 'VOID', 'VOIDED', 'DELETED', 'WRITTEN_OFF', 'CANCELLED'];

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

        $since = $this->pollingBaseline($connection);

        try {
            $outstanding = $api->fetchInvoicesCreatedSince($connection, $since);
            $oauth->recordApiSuccess($connection);
        } catch (Throwable $e) {
            $oauth->recordApiFailure($connection, $e);

            Log::error('Lawcus invoice poll failed', [
                'pms_client_id' => $connection->pms_client_id,
                'error'         => $e->getMessage(),
            ]);

            return;
        }

        $candidates = collect($outstanding)->filter(
            fn (array $invoice): bool => self::shouldIngest($invoice, $since)
        );

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
                    'event_name'    => empty($invoice['sent_at']) ? 'poll.invoice.saved' : 'poll.invoice.sent',
                    // The list's own status / amount due, in case the single-invoice fetch omits them.
                    'list_row'      => array_intersect_key($invoice, array_flip(['status', 'amount_due'])),
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
     * Whether a /v2/invoices row should be ingested. Only invoices issued since the firm
     * connected — otherwise connecting a firm would email payment links for its entire
     * existing receivables backlog. Unpaid ones get a payment link as soon as they're saved,
     * without waiting for the firm to send them — firms review time entries before invoicing,
     * so a saved invoice is final. Without Lawcus's approval process a saved invoice is
     * APPROVED; DRAFT / PENDING_APPROVAL / REJECTED only appear while an approver still has
     * to act. Ones with nothing due (e.g. paid from the matter's trust balance when created)
     * are only recorded, never emailed — see LawcusInvoiceIngestionService.
     */
    public static function shouldIngest(array $invoice, CarbonImmutable $since): bool
    {
        $issued = self::parseDate($invoice['issue_date'] ?? null);
        $status = strtoupper((string) ($invoice['status'] ?? ''));

        if ($issued === null || $issued->lessThan($since) || in_array($status, self::NOT_RECORDED_STATUSES, true)) {
            return false;
        }

        if (LawcusInvoiceIngestionService::hasNothingDue($invoice)) {
            return true;
        }

        $ready = ! empty($invoice['sent_at']) || $status === 'APPROVED';

        return $ready
            && ! in_array($status, self::NOT_PAYABLE_STATUSES, true)
            && (float) ($invoice['amount_due'] ?? 0) > 0;
    }

    /**
     * Start of the day the firm connected. Compared at day granularity because Lawcus
     * issue dates carry no timezone.
     */
    private function pollingBaseline(LawcusConnection $connection): CarbonImmutable
    {
        return CarbonImmutable::parse($connection->created_at)->startOfDay();
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
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
