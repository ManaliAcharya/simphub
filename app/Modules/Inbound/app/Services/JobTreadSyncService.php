<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\Log;
use Modules\Billing\Models\Invoice;
use Modules\Inbound\Models\JobTreadConnection;
use Throwable;

/**
 * One catch-up pass for a JobTread connection, run by the scheduled poll and after every
 * webhook. JobTread documents have no updatedAt, so there's no "changed since" query: each
 * pass reads every pending customer invoice, plus every invoice SimpHub still has open
 * (to notice Draft, Denied and payments recorded in JobTread), and lets
 * JobTreadInvoiceIngestionService decide what changed.
 *
 * Also keeps the grant key in use: keys are reported to expire after 3 months unused.
 */
class JobTreadSyncService
{
    /** Auth failures in a row before polling stops until the key is replaced. */
    public const MAX_CONSECUTIVE_FAILURES = 3;

    /** Local statuses that may still change in JobTread. */
    private const OPEN_STATUSES = ['PENDING', 'DRAFT'];

    public function __construct(
        private readonly JobTreadApiClient $api,
        private readonly JobTreadConnectionService $connections,
        private readonly JobTreadInvoiceIngestionService $ingestion,
    ) {}

    /**
     * @param  list<string>  $documentIds  documents a webhook named, read first
     * @return array{checked: int, emailed: int, failed: int}
     */
    public function sync(JobTreadConnection $connection, array $documentIds = []): array
    {
        $counts = ['checked' => 0, 'emailed' => 0, 'failed' => 0];

        if ((int) $connection->consecutive_failures >= self::MAX_CONSECUTIVE_FAILURES) {
            Log::warning('JobTread sync skipped: grant key keeps failing authentication', [
                'pms_client_id' => $connection->pms_client_id,
                'last_error'    => $connection->last_error,
            ]);

            return $counts;
        }

        if (! $connection->organizationId()) {
            return $counts;
        }

        // Nothing is ingested until invoices already pending at connect are known.
        if (! JobTreadConnectionService::hasBaseline($connection)) {
            $this->connections->recordBaseline($connection);

            return $counts;
        }

        try {
            $pending = $this->api->invoicesByStatus($connection, (string) $connection->organizationId(), JobTreadPave::STATUS_PENDING);
            $this->connections->recordApiSuccess($connection);
        } catch (Throwable $e) {
            $this->connections->recordApiFailure($connection, $e);

            Log::error('JobTread pending-invoice list failed', [
                'pms_client_id' => $connection->pms_client_id,
                'error'         => $e->getMessage(),
            ]);

            $pending = [];

            if ($documentIds === []) {
                return $counts;
            }
        }

        $open = Invoice::query()
            ->where('pms_source', JobTreadInvoiceIngestionService::SOURCE)
            ->where('pms_client_id', $connection->pms_client_id)
            ->whereIn('status', self::OPEN_STATUSES)
            ->pluck('external_invoice_id')
            ->all();

        $ids = collect($documentIds)
            ->merge(array_column($pending, 'id'))
            ->merge($open)
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->unique()
            ->values();

        foreach ($ids as $documentId) {
            $counts['checked']++;

            try {
                $result = $this->ingestion->ingest($documentId, [
                    'pms_client_id' => $connection->pms_client_id,
                    'event_name'    => in_array($documentId, $documentIds, true) ? 'webhook' : 'poll',
                ]);

                $counts['emailed'] += (int) ($result['emails_sent'] ?? 0);
            } catch (Throwable $e) {
                // Left as it was, so the next pass retries it.
                $counts['failed']++;

                Log::error('JobTread invoice sync failed', [
                    'pms_client_id' => $connection->pms_client_id,
                    'document_id'   => $documentId,
                    'error'         => $e->getMessage(),
                ]);

                if ($e instanceof JobTreadApiException && $e->isAuthFailure()) {
                    break;
                }
            }
        }

        return $counts;
    }
}
