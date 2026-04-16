<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Services\PaymentSessionService;
use Modules\Inbound\Models\ClioConnection;
use RuntimeException;

class ClioInvoiceIngestionService
{
    public function __construct(
        private readonly ClioApiClient $client,
        private readonly ClioOAuthService $oauth,
        private readonly PaymentSessionService $paymentSessions,
    ) {}

    public function ingest(string $externalInvoiceId, array $triggerPayload = []): array
    {
        $connection = $this->oauth->ensureValidAccessToken(ClioConnection::query()->first());
        $invoicePayload = $this->client->fetchBill($connection, $externalInvoiceId);
        $normalized = $this->normalizeInvoice($invoicePayload, $triggerPayload);

        return DB::transaction(function () use ($normalized, $invoicePayload, $triggerPayload) {
            $invoice = Invoice::query()->updateOrCreate(
                [
                    'pms_source' => 'clio',
                    'external_invoice_id' => $normalized['external_invoice_id'],
                ],
                [
                    'external_client_id' => $normalized['external_client_id'],
                    'external_matter_id' => $normalized['external_matter_id'],
                    'status' => $normalized['status'],
                    'fund_type' => $normalized['fund_type'],
                    'amount_cents' => $normalized['amount_cents'],
                    'currency' => $normalized['currency'],
                    'pms_sync_status' => 'SYNCED',
                    'raw_payload' => [
                        'trigger' => $triggerPayload,
                        'invoice' => $invoicePayload,
                    ],
                    'synced_at' => now(),
                ]
            );

            $session = PaymentSession::query()
                ->where('invoice_id', $invoice->id)
                ->latest('created_at')
                ->first();

            if (! $session) {
                $session = $this->paymentSessions->create($invoice);
            }

            AuditLogger::log('INVOICE_RECEIVED', 'invoice', $invoice->id, [
                'pms_source' => 'clio',
                'external_invoice_id' => $invoice->external_invoice_id,
                'payment_session_id' => $session->id,
            ]);

            return [
                'invoice' => $invoice->fresh(),
                'payment_session' => $session->fresh(),
            ];
        });
    }

    private function normalizeInvoice(array $invoicePayload, array $triggerPayload): array
    {
        $data = Arr::get($invoicePayload, 'data', $invoicePayload);
        $id = Arr::get($data, 'id');

        if (! $id) {
            throw new RuntimeException('Clio invoice response did not include an invoice id.');
        }

        $total = Arr::get($data, 'total', Arr::get($data, 'balance', 0));
        $fundType = strtoupper((string) (
            Arr::get($data, 'fund_type')
            ?? Arr::get($triggerPayload, 'fund_type')
            ?? 'OPERATING'
        ));

        return [
            'external_invoice_id' => (string) $id,
            'external_client_id' => (string) (
                Arr::get($data, 'client.id')
                ?? Arr::get($data, 'client.number')
                ?? Arr::get($data, 'client.name')
                ?? Arr::get($data, 'matter.client.id')
                ?? $id
            ),
            'external_matter_id' => Arr::get($data, 'matter.id')
                ? (string) Arr::get($data, 'matter.id')
                : null,
            'status' => strtoupper((string) (Arr::get($data, 'state') ?? 'PENDING')),
            'fund_type' => in_array($fundType, ['TRUST', 'OPERATING'], true) ? $fundType : 'OPERATING',
            'amount_cents' => $this->toCents($total),
            'currency' => strtoupper((string) (Arr::get($data, 'currency') ?? 'USD')),
        ];
    }

    private function toCents(mixed $amount): int
    {
        if (is_int($amount)) {
            return $amount;
        }

        if (is_numeric($amount)) {
            return (int) round(((float) $amount) * 100);
        }

        return 0;
    }
}
