<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\LawcusConnection;
use Throwable;

/**
 * Logs what SimpHub did on a Lawcus invoice (payment link emailed, payment recorded)
 * to the invoice's matter, so the firm's matter record stays complete even though
 * these emails don't go through Lawcus.
 *
 * Entries are written as outbound EMAIL interactions — the only documented way to
 * add activity to a matter. Best-effort: a failure is audited and never interrupts
 * the email or payment it describes.
 */
class LawcusMatterActivityService
{
    private const PRODUCT_NAME = 'SimpHub';

    public function __construct(
        private readonly LawcusApiClient $api,
    ) {}

    /**
     * @param  array<int, string>  $recipientEmails
     */
    public function logInvoiceSent(Invoice $invoice, array $recipientEmails): void
    {
        $number = $this->invoiceLabel($invoice);

        $this->log(
            $invoice,
            'invoice_sent',
            "Invoice {$number} sent via ".self::PRODUCT_NAME,
            sprintf(
                '<p>Payment link for invoice %s (%s due) emailed to %s via %s.</p>',
                e($number),
                $this->money((int) $invoice->amount_cents, (string) $invoice->currency),
                e(implode(', ', $recipientEmails) ?: 'the client'),
                self::PRODUCT_NAME,
            ),
        );
    }

    public function logPaymentRecorded(Transaction $transaction, Invoice $invoice, float $amount, string $accountName): void
    {
        $paidOn  = ($transaction->created_at ?? now())->format('m/d/Y');
        $method  = strtolower((string) $transaction->gateway) === 'paya' ? 'ACH' : 'card';
        $summary = sprintf(
            'Paid %s on %s via %s, recorded to %s',
            $this->money((int) round($amount * 100), (string) $invoice->currency),
            $paidOn,
            $method,
            $accountName,
        );

        $this->log(
            $invoice,
            'payment_recorded',
            $summary,
            sprintf(
                '<p>%s. Invoice %s, %s reference %s.</p>',
                e($summary),
                e($this->invoiceLabel($invoice)),
                self::PRODUCT_NAME,
                e((string) ($transaction->gateway_txn_id ?: $transaction->id)),
            ),
        );
    }

    private function log(Invoice $invoice, string $event, string $subject, string $body): void
    {
        $matterId  = $invoice->external_matter_id;
        $contactId = Arr::get((array) $invoice->raw_payload, 'invoice.data.client_id');

        // Interactions need the numeric matter and contact ids; without them there's
        // nothing to attach the entry to.
        if (! is_numeric($matterId) || ! is_numeric($contactId)) {
            return;
        }

        try {
            $connection = LawcusConnection::query()
                ->where('provider', 'lawcus')
                ->where('pms_client_id', $invoice->pms_client_id)
                ->latest('created_at')
                ->first();

            if (! $connection) {
                return;
            }

            $response = $this->api->createInteraction($connection, [
                'matter_id'    => (int) $matterId,
                'contact_id'   => (int) $contactId,
                'type'         => 'EMAIL',
                'type_in'      => 'OUTBOUND',
                'participants' => json_encode([['type' => 'CLIENT', 'id' => (int) $contactId]]),
                'subject'      => $subject,
                'body'         => $body,
                'date'         => now()->utc()->format('Y-m-d H:i:s'),
            ]);

            AuditLogger::log('LAWCUS_MATTER_ACTIVITY_LOGGED', 'invoice', $invoice->id, [
                'event'          => $event,
                'matter_id'      => (int) $matterId,
                'interaction_id' => $response['id'] ?? null,
                'subject'        => $subject,
            ]);
        } catch (Throwable $e) {
            AuditLogger::log('LAWCUS_MATTER_ACTIVITY_FAILED', 'invoice', $invoice->id, [
                'event'     => $event,
                'matter_id' => (int) $matterId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function invoiceLabel(Invoice $invoice): string
    {
        return '#'.($invoice->invoice_number ?: $invoice->external_invoice_id);
    }

    private function money(int $cents, string $currency): string
    {
        $amount = number_format($cents / 100, 2);

        return strtoupper($currency ?: 'USD') === 'USD' ? '$'.$amount : $amount.' '.strtoupper($currency);
    }
}
