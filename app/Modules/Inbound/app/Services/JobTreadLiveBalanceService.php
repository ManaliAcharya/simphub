<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\Log;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Models\JobTreadConnection;
use Throwable;

/**
 * Reads a JobTread invoice on every payment-page load and again at submit, so the link always
 * charges the current balance (staff record checks in JobTread; deposits get applied), and:
 * - a Draft invoice (Make Changes in progress) can't be paid until it's pushed again,
 * - a Denied, deleted or fully paid invoice closes its link.
 */
class JobTreadLiveBalanceService
{
    public const PAYABLE = 'payable';
    public const CLOSED  = 'closed';
    public const EDITING = 'editing';
    public const UNKNOWN = 'unknown';

    public const EDITING_MESSAGE = 'This invoice is being updated. Please use the link in the updated invoice email, or try again later.';

    public function __construct(
        private readonly JobTreadApiClient $api,
    ) {}

    public function sync(Invoice $invoice, PaymentSession $session): string
    {
        if ((string) $invoice->pms_source !== JobTreadInvoiceIngestionService::SOURCE || $session->status === 'COMPLETED') {
            return self::PAYABLE;
        }

        $connection = JobTreadConnection::forClient((string) $invoice->pms_client_id);

        if (! $connection) {
            return self::UNKNOWN;
        }

        try {
            $document = $this->api->fetchDocument($connection, (string) $invoice->external_invoice_id);
        } catch (JobTreadApiException $e) {
            if ($e->isNotFound()) {
                $this->close($invoice, $session, 'voided', 'DELETED');

                return self::CLOSED;
            }

            Log::warning('JobTread live balance check failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);

            return self::UNKNOWN;
        } catch (Throwable $e) {
            Log::warning('JobTread live balance check failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);

            return self::UNKNOWN;
        }

        if (JobTreadDocument::isDenied($document)) {
            $this->close($invoice, $session, 'voided', 'DENIED');

            return self::CLOSED;
        }

        if (JobTreadDocument::isDraft($document)) {
            return self::EDITING;
        }

        $balance = JobTreadDocument::balanceCents($document);

        if ($balance === null) {
            return self::UNKNOWN;
        }

        if ($balance <= 0) {
            $this->close($invoice, $session, 'paid', strtoupper(JobTreadDocument::status($document)) ?: 'APPROVED');

            return self::CLOSED;
        }

        if ($balance !== (int) $invoice->amount_cents) {
            AuditLogger::log('JOBTREAD_BALANCE_CHANGED', 'invoice', $invoice->id, [
                'external_invoice_id' => $invoice->external_invoice_id,
                'previous_cents'      => (int) $invoice->amount_cents,
                'live_cents'          => $balance,
            ]);

            $invoice->forceFill(['amount_cents' => $balance])->save();
        }

        return self::PAYABLE;
    }

    private function close(Invoice $invoice, PaymentSession $session, string $linkStatus, string $invoiceStatus): void
    {
        if ($invoice->status !== 'PAID') {
            $invoice->forceFill(['status' => $invoiceStatus])->save();
        }

        if (($session->link_status ?? 'active') !== 'active') {
            return;
        }

        $session->forceFill(['link_status' => $linkStatus, 'next_reminder_at' => null])->save();

        AuditLogger::log("payment_link.{$linkStatus}", 'payment_session', $session->id, [
            'invoice_id'          => $invoice->id,
            'pms_source'          => JobTreadInvoiceIngestionService::SOURCE,
            'pms_client_id'       => $invoice->pms_client_id,
            'external_invoice_id' => $invoice->external_invoice_id,
            'jobtread_status'     => $invoiceStatus,
            'source'              => 'live_balance_check',
        ]);
    }
}
