<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Services\PaymentSessionService;
use Modules\Inbound\Models\WaveConnection;
use Modules\Payment\Services\PaymentLinkService;
use RuntimeException;

class WaveInvoiceIngestionService
{
    public function __construct(
        private readonly WaveApiClient $client,
        private readonly WaveOAuthService $oauth,
        private readonly PaymentSessionService $paymentSessions,
        private readonly PaymentLinkService $paymentLinks,
    ) {}

    public function ingest(string $externalInvoiceId, array $triggerPayload = []): array
    {
        $pmsClientId = $this->resolvePmsClientId($triggerPayload);
        $connection  = $this->oauth->ensureValidAccessToken($this->resolveConnection($pmsClientId));
        $invoiceData = $this->client->fetchInvoice($connection, $externalInvoiceId);
        $normalized  = $this->normalizeInvoice($invoiceData, $triggerPayload);
        $emails      = $this->extractClientEmails($invoiceData);

        $result = DB::transaction(function () use ($normalized, $invoiceData, $triggerPayload, $emails, $pmsClientId) {
            $invoice = Invoice::query()->updateOrCreate(
                [
                    'pms_source'          => 'wave',
                    'pms_client_id'       => $pmsClientId,
                    'external_invoice_id' => $normalized['external_invoice_id'],
                ],
                [
                    'pms_client_id'      => $pmsClientId,
                    'external_client_id' => $normalized['external_client_id'],
                    'status'             => $normalized['status'],
                    'fund_type'          => $normalized['fund_type'],
                    'amount_cents'       => $normalized['amount_cents'],
                    'currency'           => $normalized['currency'],
                    'pms_sync_status'    => 'SYNCED',
                    'raw_payload'        => [
                        'trigger'  => $triggerPayload,
                        'invoice'  => $invoiceData,
                    ],
                    'recipient_emails'   => $emails,
                    'synced_at'          => now(),
                ]
            );

            Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();

            $session = PaymentSession::query()
                ->where('invoice_id', $invoice->id)
                ->lockForUpdate()
                ->latest('created_at')
                ->first();

            $createdSession = false;

            if (! $session) {
                $session        = $this->paymentSessions->create($invoice);
                $createdSession = true;
            }

            AuditLogger::log('INVOICE_RECEIVED', 'invoice', $invoice->id, [
                'pms_source'          => 'wave',
                'pms_client_id'       => $pmsClientId,
                'external_invoice_id' => $invoice->external_invoice_id,
                'payment_session_id'  => $session->id,
            ]);

            return [
                'invoice'         => $invoice->fresh(),
                'payment_session' => $session->fresh(),
                'created_session' => $createdSession,
            ];
        });

        $emailsSent = $this->paymentLinks->sendInvoiceLinkOnce(
            $result['invoice'],
            $result['payment_session'],
            $emails
        );

        if ($emailsSent > 0) {
            AuditLogger::log('PAYMENT_LINK_SENT', 'payment_session', $result['payment_session']->id, [
                'invoice_id'      => $result['invoice']->id,
                'pms_client_id'   => $pmsClientId,
                'emails_sent'     => $emailsSent,
                'recipient_emails' => $emails,
            ]);
        }

        $result['emails_sent'] = $emailsSent;

        return $result;
    }

    private function normalizeInvoice(array $invoice, array $triggerPayload): array
    {
        $id = Arr::get($invoice, 'id', '');

        if ($id === '') {
            throw new RuntimeException('Wave invoice response did not include an invoice id.');
        }

        $amountDue = Arr::get($invoice, 'amountDue.value', 0);
        $total     = Arr::get($invoice, 'total.value', $amountDue);
        // Wave's invoice.amountDue only returns `value`, not nested currency.
        // Fall back to the currency sent in the webhook payload.
        $currency  = Arr::get($invoice, 'amountDue.currency.code')
            ?? Arr::get($triggerPayload, 'data.currency_code')
            ?? Arr::get($triggerPayload, 'currency', 'USD');

        $waveStatus = strtoupper((string) Arr::get($invoice, 'status', 'DRAFT'));
        $status     = match ($waveStatus) {
            'PAID'          => 'PAID',
            'OVERDUE'       => 'PENDING',
            'PARTIAL'       => 'PENDING',
            'DRAFT'         => 'PENDING',
            'SENT'          => 'PENDING',
            default         => 'PENDING',
        };

        return [
            'external_invoice_id' => (string) $id,
            'external_client_id'  => (string) (Arr::get($invoice, 'customer.id') ?? $id),
            'status'              => $status,
            'fund_type'           => 'OPERATING',
            'amount_cents'        => $this->toCents($amountDue ?: $total),
            'currency'            => strtoupper(trim($currency)) ?: 'USD',
        ];
    }

    private function toCents(mixed $amount): int
    {
        if (is_array($amount)) {
            foreach (['amount_cents', 'cents', 'value', 'amount'] as $key) {
                if (array_key_exists($key, $amount)) {
                    return $this->toCents($amount[$key]);
                }
            }
            return 0;
        }

        if (is_int($amount)) {
            return $amount;
        }

        if (is_numeric($amount)) {
            return (int) round(((float) $amount) * 100);
        }

        return 0;
    }

    private function extractClientEmails(array $invoice): array
    {
        $emails = array_filter([
            Arr::get($invoice, 'customer.email'),
        ]);

        return array_values(array_unique(array_map('trim', $emails)));
    }

    private function resolvePmsClientId(array $triggerPayload): string
    {
        $pmsClientId = (string) (
            $triggerPayload['pms_client_id']
            ?? data_get($triggerPayload, 'meta.pms_client_id')
            ?? ''
        );

        if ($pmsClientId === '') {
            throw new RuntimeException('PMS client identifier is required for Wave invoice ingestion.');
        }

        return $pmsClientId;
    }

    private function resolveConnection(string $pmsClientId): WaveConnection
    {
        $connection = WaveConnection::query()
            ->where('provider', 'wave')
            ->where('pms_client_id', $pmsClientId)
            ->first();

        if (! $connection) {
            throw new RuntimeException("Unknown Wave PMS client identifier [{$pmsClientId}].");
        }

        return $connection;
    }
}
