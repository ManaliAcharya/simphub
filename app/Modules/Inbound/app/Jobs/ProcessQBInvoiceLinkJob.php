<?php

namespace Modules\Inbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\QuickBooksConnection;
use Modules\Inbound\Services\QuickBooksApiClient;
use Modules\Payment\Services\PaymentLinkService;

class ProcessQBInvoiceLinkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly string $entityName,
        public readonly string $operation,
        public readonly string $entityId,
        public readonly string $realmId,
        public readonly string $pmClientId,
    ) {}

    public function handle(QuickBooksApiClient $qbApi, PaymentLinkService $linkService): void
    {
        $connection = QuickBooksConnection::query()
            ->where('provider', 'quickbooks')
            ->where('pms_client_id', $this->pmClientId)
            ->first();

        if (! $connection) {
            Log::warning('ProcessQBInvoiceLinkJob: no QB connection found', [
                'pms_client_id' => $this->pmClientId,
                'realm_id'      => $this->realmId,
            ]);
            return;
        }

        match ($this->entityName) {
            'invoice' => $this->handleInvoice($connection, $qbApi, $linkService),
            'payment' => $this->handlePayment($connection, $qbApi),
            default   => null,
        };
    }

    private function handleInvoice(
        QuickBooksConnection $connection,
        QuickBooksApiClient $qbApi,
        PaymentLinkService $linkService,
    ): void {
        $invoice = Invoice::query()
            ->where('external_invoice_id', $this->entityId)
            ->where('pms_source', 'quickbooks')
            ->first();

        if (! $invoice) {
            return;
        }

        $session = PaymentSession::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('link_status', ['active'])
            ->latest()
            ->first();

        if ($this->operation === 'delete') {
            $this->disableLink($invoice, $session, 'disabled');
            return;
        }

        if ($this->operation === 'void') {
            $this->disableLink($invoice, $session, 'voided');
            return;
        }

        // Update — compare amounts
        if ($this->operation === 'update' && $session) {
            $this->handleInvoiceUpdate($connection, $qbApi, $linkService, $invoice, $session);
        }
    }

    private function handleInvoiceUpdate(
        QuickBooksConnection $connection,
        QuickBooksApiClient $qbApi,
        PaymentLinkService $linkService,
        Invoice $invoice,
        PaymentSession $session,
    ): void {
        $client = Client::query()->where('pms_client_id', $invoice->pms_client_id)->first();

        if (! ($client?->auto_resend_on_change)) {
            return;
        }

        // Enforce 15-minute cooldown
        $lastSent = $session->last_email_sent_at ?? $session->payment_link_sent_at;
        if ($lastSent && $lastSent->diffInMinutes(now()) < 15) {
            Log::info('ProcessQBInvoiceLinkJob: invoice update within cooldown, skipping resend', [
                'session_id' => $session->id,
                'last_sent'  => (string) $lastSent,
            ]);
            return;
        }

        // Fetch live invoice and compare amount
        try {
            $liveData = $qbApi->fetchInvoice($connection, $this->entityId);
        } catch (\Throwable $e) {
            Log::error('ProcessQBInvoiceLinkJob: failed to fetch live invoice', [
                'invoice_id' => $this->entityId,
                'error'      => $e->getMessage(),
            ]);
            return;
        }

        // Compare against Balance (what ingestion stores in amount_cents), not TotalAmt.
        // TotalAmt stays fixed when a partial external payment reduces the Balance —
        // comparing TotalAmt vs stored Balance would produce a false diff on every view.
        $invoiceData     = Arr::get($liveData, 'Invoice', $liveData);
        $liveBalance     = (float) ($invoiceData['Balance'] ?? $invoiceData['TotalAmt'] ?? 0);
        $originalCents   = (int) ($invoice->amount_cents ?? 0);
        $originalDollars = $originalCents / 100;

        $absoluteDiff = abs($liveBalance - $originalDollars);
        $relativeDiff = $originalDollars > 0 ? ($absoluteDiff / $originalDollars) : 0;

        if ($absoluteDiff < 1.00 && $relativeDiff < 0.01) {
            return;
        }

        // Clear stale PDF cache and fetch fresh PDF
        $qbApi->clearPdfCache($connection, $this->entityId);
        $pdf = $qbApi->fetchInvoicePdf($connection, $this->entityId);

        // Record original amount on session before first resend
        if ($session->original_amount === null) {
            $session->forceFill(['original_amount' => $originalDollars])->save();
        }

        $linkService->resendPaymentLink($invoice, $session, $pdf);

        AuditLogger::log(
            'payment_link.resent_on_invoice_update',
            'payment_session',
            $session->id,
            [
                'invoice_id'    => $invoice->id,
                'qb_invoice_id' => $this->entityId,
                'old_amount'    => $originalDollars,
                'new_amount'    => $liveBalance,
            ]
        );
    }

    private function handlePayment(QuickBooksConnection $connection, QuickBooksApiClient $qbApi): void
    {
        if ($this->operation !== 'create') {
            return;
        }

        try {
            $paymentData = $qbApi->readPayment($connection, $this->entityId);
        } catch (\Throwable $e) {
            Log::error('ProcessQBInvoiceLinkJob: failed to fetch QB payment', [
                'payment_id' => $this->entityId,
                'error'      => $e->getMessage(),
            ]);
            return;
        }

        // Find the linked invoice from the payment lines
        $linkedTxns = data_get($paymentData, 'Payment.Line', []);
        $qbInvoiceId = null;

        foreach ((array) $linkedTxns as $line) {
            foreach ((array) data_get($line, 'LinkedTxn', []) as $linked) {
                if (strtolower((string) data_get($linked, 'TxnType', '')) === 'invoice') {
                    $qbInvoiceId = (string) data_get($linked, 'TxnId', '');
                    break 2;
                }
            }
        }

        if (! $qbInvoiceId) {
            return;
        }

        // Fetch live invoice to confirm balance is zero
        try {
            $liveData = $qbApi->fetchInvoice($connection, $qbInvoiceId);
        } catch (\Throwable) {
            return;
        }

        $balance = (float) data_get($liveData, 'Invoice.Balance', 1);

        if ($balance > 0) {
            return;
        }

        $invoice = Invoice::query()
            ->where('external_invoice_id', $qbInvoiceId)
            ->where('pms_source', 'quickbooks')
            ->first();

        if (! $invoice) {
            return;
        }

        $session = PaymentSession::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('link_status', ['active'])
            ->latest()
            ->first();

        if (! $session) {
            return;
        }

        $session->forceFill(['link_status' => 'paid'])->save();

        AuditLogger::log(
            'payment_link.marked_paid_via_qb_payment',
            'payment_session',
            $session->id,
            [
                'invoice_id'    => $invoice->id,
                'qb_invoice_id' => $qbInvoiceId,
                'qb_payment_id' => $this->entityId,
            ]
        );
    }

    private function disableLink(Invoice $invoice, ?PaymentSession $session, string $status): void
    {
        if (! $session) {
            return;
        }

        $session->forceFill(['link_status' => $status])->save();

        AuditLogger::log(
            "payment_link.{$status}",
            'payment_session',
            $session->id,
            [
                'invoice_id'    => $invoice->id,
                'qb_invoice_id' => $this->entityId,
                'operation'     => $this->operation,
            ]
        );
    }
}
