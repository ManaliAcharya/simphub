<?php

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Models\Transaction;
use Modules\Billing\States\SessionStateMachine;
use Modules\Inbound\Models\Client;
use Modules\Outbound\DTOs\ChargeRequest;
use Modules\Outbound\Factory\GatewayAdapterFactory;
use Modules\Payment\Events\PaymentApproved;
use Modules\Inbound\Services\ClioCustomerRefreshService;
use Modules\Inbound\Services\LawcusCustomerRefreshService;
use Modules\Inbound\Services\QuickBooksCustomerRefreshService;
use Modules\Inbound\Services\ZohoCustomerRefreshService;
use Modules\Routing\DTOs\RoutingContext;
use Modules\Routing\Services\RoutingEngine;
use RuntimeException;

class PaymentCheckoutService
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly GatewayAdapterFactory $gateways,
        private readonly RoutingEngine $routing,
        private readonly SessionStateMachine $stateMachine,
        private readonly ZohoCustomerRefreshService $zohoCustomerRefresh,
        private readonly ClioCustomerRefreshService $clioCustomerRefresh,
        private readonly QuickBooksCustomerRefreshService $quickBooksCustomerRefresh,
        private readonly LawcusCustomerRefreshService $lawcusCustomerRefresh,
    ) {}

    public function details(PaymentSession $session): array
    {
        $invoice = $session->invoice()->firstOrFail();
        $options = $this->filterAllowedGateways(
            $this->routingCandidates($session),
            $invoice->pms_client_id,
        );

        if ($options->isEmpty()) {
            throw new RuntimeException('No routing rule available for this payment session.');
        }

        return [
            'session' => [
                'id' => $session->id,
                'token' => $session->hosted_url_token,
                'status' => $session->status,
                'expires_at' => optional($session->expires_at)->toIso8601String(),
            ],
            'invoice' => [
                'id' => $invoice->id,
                'external_invoice_id' => $invoice->external_invoice_id,
                'amount_cents' => $invoice->amount_cents,
                'currency' => $invoice->currency,
                'fund_type' => $invoice->fund_type,
                'status' => $invoice->status,
                'client_emails' => array_values(array_filter((array) $invoice->recipient_emails)),
            ],
            'payment_options' => $options->map(function ($decision) use ($invoice) {
                $availability = strtolower((string) $decision->gateway) === 'paya'
                    ? $this->payaAvailability($invoice)
                    : ['available' => true, 'reason' => null];
                $hostedFields = $this->gateways->make($decision->gateway)->hostedFieldsConfig(
                    $decision->mid,
                    $decision->midCredentials,
                );

                return [
                    'routing_rule_id' => $decision->routingRuleId,
                    'gateway' => $decision->gateway,
                    'mid' => $decision->mid,
                    'payment_method' => $decision->ruleMatches['payment_method'] ?? 'CARD',
                    'rule_matches' => $decision->ruleMatches,
                    'hosted_fields' => [
                        'gateway' => $hostedFields->gateway,
                        'fields' => $hostedFields->fields,
                        'metadata' => $hostedFields->metadata,
                    ],
                    'is_available' => $availability['available'],
                    'unavailable_reason' => $availability['reason'],
                ];
            })->values()->all(),
        ];
    }

    public function submit(
        PaymentSession $session,
        string $token,
        string $paymentMethod = 'CARD',
        ?string $routingRuleId = null
    ): Transaction {
        $invoice = $session->invoice()->firstOrFail();

        if ($token === '') {
            throw new RuntimeException('Payment token is required.');
        }

        if (! $routingRuleId) {
            throw new RuntimeException('Payment option selection is required.');
        }

        if ($session->status === 'COMPLETED') {
            $existing = Transaction::query()
                ->where('payment_session_id', $session->id)
                ->latest('created_at')
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $existing = $this->idempotency->acquireLock((string) $session->idempotency_key);

        if ($existing) {
            return $existing;
        }

        $session->forceFill([
            'status' => 'PROCESSING',
            'payment_method' => $paymentMethod,
        ])->save();

        $routingContext = $this->makeRoutingContext($session, $paymentMethod);
        $decision = $this->routing->findCandidate($routingContext, $routingRuleId);

        AuditLogger::log('ROUTING_DECISION_MADE', 'payment_session', $session->id, [
            'gateway' => $decision->gateway,
            'mid' => $decision->mid,
            'routing_rule_id' => $decision->routingRuleId,
        ]);

        $billing = [];
        if (strtolower((string) $decision->gateway) === 'paya') {
            $bankDetails = $this->resolvePayaBankDetails($invoice);

            if ($bankDetails['missing'] !== []) {
                // One last chance: re-fetch the customer from the invoice's PMS
                // in case bank details were added after the invoice was ingested.
                $refreshed = $this->refreshCustomerPayload($invoice);

                if ($refreshed !== null) {
                    $refreshed->save();
                    $invoice     = $refreshed->fresh();
                    $bankDetails = $this->resolvePayaBankDetails($invoice);
                }
            }

            if ($bankDetails['missing'] !== []) {
                throw new RuntimeException('Payment cannot be done because account number and routing number are missing in invoice custom fields.');
            }

            $billing = [
                'account_number' => $bankDetails['account_number'],
                'routing_number' => $bankDetails['routing_number'],
            ];
        }

        $response = $this->gateways->make($decision->gateway)->charge(new ChargeRequest(
            token: $token,
            amountInCents: (int) $invoice->amount_cents,
            currency: (string) $invoice->currency,
            idempotencyKey: (string) $session->idempotency_key,
            midCredentials: $decision->midCredentials,
            billing: $billing,
            metadata: [
                'invoice_id' => $invoice->id,
                'payment_session_id' => $session->id,
                'routing_rule_id' => $routingRuleId,
            ],
        ));

        if (! $response->approved) {
            $session->forceFill(['status' => 'FAILED'])->save();
            $invoice->forceFill([
                'status' => 'FAILED',
                'pms_sync_status' => 'FAILED',
            ])->save();
            $this->idempotency->fail((string) $session->idempotency_key);
            AuditLogger::log('PAYMENT_DECLINED', 'payment_session', $session->id, [
                'message' => $response->message,
                'gateway' => $decision->gateway,
            ]);

            throw new RuntimeException($response->message ?? 'Payment was declined.');
        }

        $transaction = DB::transaction(function () use ($session, $invoice, $decision, $response) {
            $transaction = Transaction::query()->create([
                'payment_session_id' => $session->id,
                'invoice_id' => $invoice->id,
                'routing_rule_id' => $decision->routingRuleId,
                'gateway' => $decision->gateway,
                'mid' => $decision->mid,
                'gateway_txn_id' => $response->transactionReference,
                'gateway_token' => (string) $response->gatewayToken,
                'status' => 'CAPTURED',
                'fund_type' => $session->fund_type,
                'amount_cents' => $invoice->amount_cents,
                'currency' => $invoice->currency,
                'gateway_response' => $response->raw,
            ]);

            $this->stateMachine->transition($session, 'COMPLETED');
            $session->forceFill([
                'status' => 'COMPLETED',
                'completed_at' => now(),
            ])->save();

            $invoice->forceFill([
                'status' => 'PAID',
                'pms_sync_status' => 'SYNCED',
            ])->save();

            $this->idempotency->complete((string) $session->idempotency_key, $transaction);

            AuditLogger::log('PAYMENT_APPROVED', 'transaction', $transaction->id, [
                'payment_session_id' => $session->id,
                'gateway' => $decision->gateway,
                'gateway_txn_id' => $response->transactionReference,
            ]);

            return $transaction;
        });

        event(new PaymentApproved((string) $transaction->id, [
            'invoice_id' => $invoice->id,
            'payment_session_id' => $session->id,
        ]));

        return $transaction;
    }

    private function makeRoutingContext(PaymentSession $session, string $paymentMethod): RoutingContext
    {
        $invoice = $session->invoice()->firstOrFail();

        return new RoutingContext(
            'default',
            (int) $invoice->amount_cents,
            $paymentMethod,
            (string) $session->fund_type,
            (string) $session->id,
            (string) $invoice->currency,
            [],
        );
    }

    private function filterAllowedGateways($options, ?string $pmsClientId)
    {
        if (! $pmsClientId) {
            return $options;
        }

        $client = Client::query()
            ->where('pms_client_id', $pmsClientId)
            ->first();

        $allowedGateways = collect($client?->allowed_payment_gateways ?? [])
            ->map(fn ($gateway) => strtolower((string) $gateway))
            ->filter()
            ->values();

        if ($allowedGateways->isEmpty()) {
            return $options;
        }

        return $options
            ->filter(fn ($decision) => $allowedGateways->contains(strtolower((string) $decision->gateway)))
            ->values();
    }

    private function routingCandidates(PaymentSession $session)
    {
        return collect(['CARD', 'ACH'])
            ->flatMap(fn ($paymentMethod) => $this->routing->candidates($this->makeRoutingContext($session, $paymentMethod)))
            ->unique(fn ($decision) => $decision->routingRuleId)
            ->values();
    }

    private function payaAvailability(Invoice $invoice): array
    {
        $details = $this->resolvePayaBankDetails($invoice);

        if ($details['missing'] !== []) {
            $refreshed = $this->refreshCustomerPayload($invoice);

            if ($refreshed !== null) {
                $refreshed->save();
                $details = $this->resolvePayaBankDetails($refreshed);
            }
        }

        if ($details['missing'] !== []) {
            return [
                'available' => false,
                'reason'    => 'Missing account number / routing number in customer custom fields.',
            ];
        }

        return [
            'available' => true,
            'reason'    => null,
        ];
    }

    /**
     * Route the customer-payload refresh to the correct PMS service based on
     * the invoice's pms_source. Returns the unsaved patched invoice on success,
     * or null when the PMS is unsupported / refresh fails / no data found.
     */
    private function refreshCustomerPayload(Invoice $invoice): ?Invoice
    {
        return match ((string) $invoice->pms_source) {
            'zoho'        => $this->zohoCustomerRefresh->refreshCustomerPayload($invoice),
            'clio'        => $this->clioCustomerRefresh->refreshCustomerPayload($invoice),
            'quickbooks'  => $this->quickBooksCustomerRefresh->refreshCustomerPayload($invoice),
            'lawcus'      => $this->lawcusCustomerRefresh->refreshCustomerPayload($invoice),
            default       => null,
        };
    }

    private function resolvePayaBankDetails(Invoice $invoice): array
    {
        $rawPayload = (array) ($invoice->raw_payload ?? []);

        // Scope extraction to the customer block only.
        // Both Zoho and Clio store the refreshed contact under this key.
        // Falling back to the full payload ensures backwards-compatibility
        // with invoices ingested before the customer block existed.
        $customerBlock = isset($rawPayload['customer']) && is_array($rawPayload['customer'])
            ? $rawPayload['customer']
            : $rawPayload;

        $flatPairs = [];
        $this->collectLabeledValuePairs($customerBlock, $flatPairs);
        $this->collectFlatPairs($customerBlock, $flatPairs);

        $account = '';
        $routing = '';

        foreach ($flatPairs as $pair) {
            $key   = strtolower((string) ($pair['key'] ?? ''));
            $value = trim((string) ($pair['value'] ?? ''));

            if ($value === '') {
                continue;
            }

            if ($account === '' && str_contains($key, 'account') && str_contains($key, 'number')) {
                $account = preg_replace('/\s+/', '', $value) ?? '';
            }

            if ($routing === '' && str_contains($key, 'routing') && str_contains($key, 'number')) {
                $routing = preg_replace('/\s+/', '', $value) ?? '';
            }
        }

        $missing = [];
        if ($account === '') {
            $missing[] = 'account_number';
        }
        if ($routing === '') {
            $missing[] = 'routing_number';
        }

        return [
            'account_number' => $account,
            'routing_number' => $routing,
            'missing'        => $missing,
        ];
    }

    /**
     * Walk every node in $payload. When a node has a human-readable label key
     * paired with a value key, emit that as a { key, value } pair.
     *
     * Handles both PMS shapes:
     *
     *   Zoho custom_fields:
     *     { "label": "Account Number", "value": "123456789" }
     *
     *   Clio custom_field_values:
     *     { "field_name": "Account Number", "value": "123456789" }
     *
     * Label candidates are tried in priority order so the most descriptive
     * name wins. Value candidates cover every spelling either API uses.
     * Nodes whose value resolves to null/empty are skipped — the caller's
     * loop already guards on empty string, but skipping early avoids noise.
     */
    private function collectLabeledValuePairs(array $payload, array &$pairs): void
    {
        $label = $this->firstNonEmptyString([
            $payload['label']      ?? null,   // Zoho custom_fields
            $payload['field_name'] ?? null,   // Clio custom_field_values
            $payload['api_name']   ?? null,   // Zoho alt shape
            $payload['Name']       ?? null,   // QuickBooks custom fields
            $payload['name']       ?? null,
            $payload['title']      ?? null,
        ]);

        // Accept the value only when it is a non-null, non-empty scalar.
        // Clio sends null for unfilled fields — we must not emit those.
        $rawValue = $payload['value']       // Zoho + Clio primary key
            ?? $payload['field_value']      // Zoho alt
            ?? $payload['StringValue']      // QuickBooks custom field (string type)
            ?? $payload['content']
            ?? $payload['text']
            ?? null;

        $value = (is_scalar($rawValue) && $rawValue !== null)
            ? $this->firstNonEmptyString([(string) $rawValue])
            : null;

        if ($label !== null && $value !== null) {
            $pairs[] = [
                'key'   => $label,
                'value' => $value,
            ];
        }

        foreach ($payload as $node) {
            if (is_array($node)) {
                $this->collectLabeledValuePairs($node, $pairs);
            }
        }
    }

    /**
     * Recursively emit scalar leaf nodes as { key, value } pairs using their
     * array key as the label.
     *
     * This is the fallback for payloads that store values as flat key-value
     * maps (e.g. Zoho's custom_field_hash) rather than labeled-value objects.
     * Numeric keys (from sequential arrays) are skipped because they carry no
     * semantic meaning and would never match "account"+"number".
     * Null values are also skipped — an empty slot is not a found value.
     */
    private function collectFlatPairs(array $payload, array &$pairs): void
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $this->collectFlatPairs($value, $pairs);
                continue;
            }

            // Skip numeric keys — they come from sequential arrays and the
            // index number is never a meaningful field name.
            if (is_int($key)) {
                continue;
            }

            // Skip null and non-scalar values.
            if (! is_scalar($value) || $value === null) {
                continue;
            }

            $pairs[] = [
                'key'   => (string) $key,
                'value' => (string) $value,
            ];
        }
    }

    private function firstNonEmptyString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $trimmed = trim($candidate);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }
}
