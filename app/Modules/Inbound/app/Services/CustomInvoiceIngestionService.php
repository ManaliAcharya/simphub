<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Services\PaymentSessionService;

class CustomInvoiceIngestionService
{
    public function __construct(
        private readonly PaymentSessionService $paymentSessions,
    ) {}

    public function ingest(string $externalInvoiceId, array $payload = []): array
    {
        $pmsClientId = (string) ($payload['pms_client_id'] ?? '');

        return DB::transaction(function () use ($externalInvoiceId, $payload, $pmsClientId) {
            $invoice = Invoice::query()->updateOrCreate(
                [
                    'pms_source'          => 'custom',
                    'pms_client_id'       => $pmsClientId,
                    'external_invoice_id' => $externalInvoiceId,
                ],
                [
                    'external_client_id'  => (string) ($payload['external_client_id'] ?? ''),
                    'external_matter_id'  => (string) ($payload['external_matter_id'] ?? ''),
                    'amount_cents'        => (int) ($payload['amount_cents'] ?? 0),
                    'currency'            => strtoupper((string) ($payload['currency'] ?? 'USD')),
                    'fund_type'           => strtoupper((string) ($payload['fund_type'] ?? 'OPERATING')),
                    'status'              => 'PENDING',
                    'pms_sync_status'     => 'SYNCED',
                    'recipient_emails'    => $payload['recipient_emails'] ?? [],
                    // Store bank details in raw_payload so resolvePayaBankDetails()
                    // can find them via its flat key-value pair extractor.
                    'raw_payload'         => [
                        'account_number' => (string) ($payload['account_number'] ?? ''),
                        'routing_number' => (string) ($payload['routing_number'] ?? ''),
                        'account_type'   => (string) ($payload['account_type'] ?? 'checking'),
                    ],
                    'synced_at'           => now(),
                ]
            );

            $session = PaymentSession::query()
                ->where('invoice_id', $invoice->id)
                ->lockForUpdate()
                ->latest('created_at')
                ->first();

            if (! $session) {
                $session = $this->paymentSessions->create($invoice);
            }

            AuditLogger::log('INVOICE_RECEIVED', 'invoice', $invoice->id, [
                'pms_source'          => 'custom',
                'pms_client_id'       => $pmsClientId,
                'external_invoice_id' => $invoice->external_invoice_id,
            ]);

            return [
                'invoice'         => $invoice,
                'payment_session' => $session,
                'emails_sent'     => 0,
            ];
        });
    }
}
