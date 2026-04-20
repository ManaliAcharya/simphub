<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Services\PaymentSessionService;
use Modules\Inbound\Models\ClioConnection;
use Modules\Payment\Services\PaymentLinkService;
use RuntimeException;

class ClioInvoiceIngestionService
{
    public function __construct(
        private readonly ClioApiClient $client,
        private readonly ClioOAuthService $oauth,
        private readonly PaymentSessionService $paymentSessions,
        private readonly PaymentLinkService $paymentLinks,
    ) {}

    public function ingest(string $externalInvoiceId, array $triggerPayload = []): array
    {
        $connection = $this->oauth->ensureValidAccessToken(ClioConnection::query()->first());
        $invoicePayload = $this->client->fetchBill($connection, $externalInvoiceId);
        $normalized = $this->normalizeInvoice($invoicePayload, $triggerPayload);
        $recipientEmails = $this->extractClientEmails($invoicePayload);

        $result = DB::transaction(function () use ($normalized, $invoicePayload, $triggerPayload, $recipientEmails) {
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
                    'recipient_emails' => $recipientEmails,
                    'synced_at' => now(),
                ]
            );

            $session = PaymentSession::query()
                ->where('invoice_id', $invoice->id)
                ->latest('created_at')
                ->first();

            $createdSession = false;

            if (! $session) {
                $session = $this->paymentSessions->create($invoice);
                $createdSession = true;
            }

            AuditLogger::log('INVOICE_RECEIVED', 'invoice', $invoice->id, [
                'pms_source' => 'clio',
                'external_invoice_id' => $invoice->external_invoice_id,
                'payment_session_id' => $session->id,
            ]);

            return [
                'invoice' => $invoice->fresh(),
                'payment_session' => $session->fresh(),
                'created_session' => $createdSession,
            ];
        });

        $emailsSent = 0;

        if ($result['created_session']) {
            $emailsSent = $this->paymentLinks->sendInvoiceLink(
                $result['invoice'],
                $result['payment_session'],
                $recipientEmails
            );

            if ($emailsSent > 0) {
                AuditLogger::log('PAYMENT_LINK_SENT', 'payment_session', $result['payment_session']->id, [
                    'invoice_id' => $result['invoice']->id,
                    'emails_sent' => $emailsSent,
                    'recipient_emails' => $recipientEmails,
                ]);
            }
        }

        $result['emails_sent'] = $emailsSent;

        return $result;
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
            'amount_cents' => $this->extractAmountInCents($data, $triggerPayload, $total),
            'currency' => $this->extractCurrency($data, $triggerPayload, $total),
        ];
    }

    private function extractAmountInCents(array $data, array $triggerPayload, mixed $primaryAmount): int
    {
        foreach ([
            $primaryAmount,
            Arr::get($data, 'total.amount'),
            Arr::get($data, 'total.value'),
            Arr::get($data, 'total.amount_decimal'),
            Arr::get($data, 'balance.amount'),
            Arr::get($data, 'balance.value'),
            Arr::get($data, 'balance.amount_decimal'),
            Arr::get($triggerPayload, 'total'),
            Arr::get($triggerPayload, 'amount'),
            Arr::get($triggerPayload, 'amount_cents'),
        ] as $candidate) {
            $cents = $this->toCents($candidate);

            if ($cents > 0) {
                return $cents;
            }
        }

        return 0;
    }

    private function toCents(mixed $amount): int
    {
        if (is_array($amount)) {
            foreach (['amount_cents', 'cents', 'amount', 'value', 'amount_decimal'] as $key) {
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

    private function extractCurrency(array $data, array $triggerPayload, mixed $primaryAmount): string
    {
        foreach ([
            Arr::get($data, 'currency'),
            Arr::get($data, 'total.currency'),
            Arr::get($data, 'balance.currency'),
            is_array($primaryAmount) ? ($primaryAmount['currency'] ?? null) : null,
            Arr::get($triggerPayload, 'currency'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return strtoupper(trim($candidate));
            }
        }

        return 'USD';
    }

    private function extractClientEmails(array $invoicePayload): array
    {
        $data = (array) Arr::get($invoicePayload, 'data', []);
        $emails = [
            Arr::get($data, 'client.email'),
            Arr::get($data, 'client.primary_email_address'),
        ];

        foreach ((array) Arr::get($data, 'client.emails', []) as $email) {
            $emails[] = is_array($email) ? ($email['address'] ?? null) : $email;
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($email) => is_string($email) ? trim($email) : null,
            $emails
        ))));
    }
}
