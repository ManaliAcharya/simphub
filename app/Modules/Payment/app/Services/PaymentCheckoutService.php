<?php

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Models\Transaction;
use Modules\Billing\States\SessionStateMachine;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClientMidRoute;
use Modules\Outbound\DTOs\ChargeRequest;
use Modules\Outbound\Factory\GatewayAdapterFactory;
use Modules\Payment\Events\PaymentApproved;
use Modules\Inbound\Services\ClioCustomerRefreshService;
use Modules\Inbound\Services\LawcusCustomerRefreshService;
use Modules\Inbound\Services\QuickBooksCustomerRefreshService;
use Modules\Inbound\Services\ZohoCustomerRefreshService;
use Modules\Inbound\Jobs\DispatchCustomWebhookJob;
use Modules\Outbound\Services\PayaTokenizerService;
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
        private readonly PayaTokenizerService $payaTokenizer,
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
                'id'                   => $invoice->id,
                'external_invoice_id'  => $invoice->external_invoice_id,
                'invoice_number'       => $invoice->invoice_number ?: null,
                'amount_cents'         => $invoice->amount_cents,
                'currency'             => $invoice->currency,
                'fund_type'            => $invoice->fund_type,
                'status'               => $invoice->status,
                'client_emails'        => array_values(array_filter((array) $invoice->recipient_emails)),
                'success_redirect_url' => $invoice->success_redirect_url ?: null,
                'cancel_redirect_url'  => $invoice->cancel_redirect_url ?: null,
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
        ?string $routingRuleId = null,
        string $transactionType = 'debit',
        array $extraBilling = [],
    ): Transaction {
        $invoice = $session->invoice()->firstOrFail();

        // ── Fee surcharge ──────────────────────────────────────────────────
        $feeCents = 0;
        if ($invoice->pms_client_id) {
            $feeClient = Client::query()->where('pms_client_id', $invoice->pms_client_id)->first();
            if ($feeClient && $feeClient->fee_surcharge_enabled) {
                $isAch      = strtoupper($paymentMethod) === 'ACH';
                $feePercent = (float) ($isAch ? $feeClient->ach_fee_percent : $feeClient->cc_fee_percent);
                if ($feePercent > 0) {
                    $feeCents = (int) round($invoice->amount_cents * $feePercent / 100);
                }
            }
        }
        $totalAmountCents = (int) $invoice->amount_cents + $feeCents;
        // ──────────────────────────────────────────────────────────────────

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
            if (! empty($extraBilling) && isset($extraBilling['paya_bank_token'])) {
                // AccountForm vault token received directly from Paya's hosted iframe.
                $billing = ['paya_token' => $extraBilling['paya_bank_token']];
            } elseif (! empty($extraBilling)) {
                // Caller already resolved billing (e.g. a Paya vault token from tokenizeViaPaya()).
                $billing = $extraBilling;
            } elseif (in_array((string) $invoice->pms_source, ['custom', 'wave'], true)) {
                // Custom and Wave PMS: always tokenize from static env ACH credentials.
                // Direct ACH path is unreliable; tokenize first, then charge with the vault token.
                \Log::debug('Paya: tokenizing static env credentials', [
                    'invoice_id' => $invoice->id,
                    'pms_source' => $invoice->pms_source,
                    'routing'    => config('services.paya.routing_number', env('PAYA_ROUTING_NUMBER')),
                    'account'    => config('services.paya.account_number', env('PAYA_ACCOUNT_NUMBER')),
                ]);

                $payaToken = $this->payaTokenizer->tokenizeViaPaya([
                    'routing_number' => env('PAYA_ROUTING_NUMBER', '490000018'),
                    'account_number' => env('PAYA_ACCOUNT_NUMBER', '123456789'),
                    'account_type'   => 'checking',
                ], $decision->midCredentials);

                \Log::debug('Paya: token acquired', ['token' => substr($payaToken, 0, 8).'...']);

                $billing = ['paya_token' => $payaToken];
            } else {
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
                    // Fall back to the system-configured default ACH credentials.
                    $defaultRouting = env('PAYA_ROUTING_NUMBER', '');
                    $defaultAccount = env('PAYA_ACCOUNT_NUMBER', '');

                    if ($defaultRouting === '' || $defaultAccount === '') {
                        throw new RuntimeException('Payment cannot be done because account number and routing number are missing in invoice custom fields and no default PAYA_ROUTING_NUMBER / PAYA_ACCOUNT_NUMBER is configured.');
                    }

                    $payaToken = $this->payaTokenizer->tokenizeViaPaya([
                        'routing_number' => $defaultRouting,
                        'account_number' => $defaultAccount,
                        'account_type'   => 'checking',
                    ], $decision->midCredentials);

                    $billing = ['paya_token' => $payaToken];
                } else {
                    $billing = [
                        'account_number' => $bankDetails['account_number'],
                        'routing_number' => $bankDetails['routing_number'],
                    ];
                }
            }
        }

        // ── QB Multi-MID override ──────────────────────────────────────────
        $qbMidRoute = null;
        if ($invoice->pms_client_id && (string) $invoice->pms_source === 'quickbooks') {
            $qbMidRoute = $this->resolveQbMidRoute($invoice, $feeClient, $decision->gateway);
        }
        if ($qbMidRoute) {
            // Override MID credentials with route-specific ones
            $decision->mid            = $qbMidRoute->mid_identifier;
            $decision->midCredentials = (array) ($qbMidRoute->credentials ?? $decision->midCredentials);

            if ($qbMidRoute->route_type === 'fees_off') {
                // Merchant absorbs fee — customer is charged flat invoice amount
                $feeCents         = 0;
                $totalAmountCents = (int) $invoice->amount_cents;
            } elseif ($qbMidRoute->rate_percent !== null) {
                // fees_on: use route-specific rate for the gateway
                $overridePercent  = (float) $qbMidRoute->rate_percent;
                $feeCents         = (int) round($invoice->amount_cents * $overridePercent / 100);
                $totalAmountCents = (int) $invoice->amount_cents + $feeCents;
            }
        }
        // ──────────────────────────────────────────────────────────────────

        \Log::debug('PaymentCheckoutService: dispatching charge', [
            'gateway'      => $decision->gateway,
            'invoice_id'   => $invoice->id,
            'pms_source'   => $invoice->pms_source,
            'amount'       => $invoice->amount_cents,
            'fee_cents'    => $feeCents,
            'total'        => $totalAmountCents,
            'qb_mid_route' => $qbMidRoute?->id,
            'billing_keys' => array_keys($billing),
        ]);

        $response = $this->gateways->make($decision->gateway)->charge(new ChargeRequest(
            token: $token,
            amountInCents: $totalAmountCents,
            currency: (string) $invoice->currency,
            idempotencyKey: (string) $session->idempotency_key,
            midCredentials: $decision->midCredentials,
            billing: $billing,
            metadata: [
                'invoice_id' => $invoice->id,
                'payment_session_id' => $session->id,
                'routing_rule_id' => $routingRuleId,
            ],
            transactionType: $transactionType,
        ));

        \Log::debug('PaymentCheckoutService: charge response', [
            'approved' => $response->approved,
            'message'  => $response->message,
            'txn_ref'  => $response->transactionReference,
            'raw'      => $response->raw,
        ]);

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

            if ((string) $invoice->pms_source === 'custom') {
                $webhookClient = Client::query()->where('pms_client_id', $invoice->pms_client_id)->first();
                if (! empty($webhookClient?->webhook_url)) {
                    DispatchCustomWebhookJob::dispatch($invoice->id, 'invoice.failed');
                }
            }

            throw new RuntimeException($response->message ?? 'Payment was declined.');
        }

        $transaction = DB::transaction(function () use ($session, $invoice, $decision, $response, $feeCents, $totalAmountCents) {
            $transaction = Transaction::query()->create([
                'payment_session_id' => $session->id,
                'invoice_id'         => $invoice->id,
                'routing_rule_id'    => $decision->routingRuleId,
                'gateway'            => $decision->gateway,
                'mid'                => $decision->mid,
                'gateway_txn_id'     => $response->transactionReference,
                'gateway_token'      => (string) $response->gatewayToken,
                'status'             => 'CAPTURED',
                'transaction_type'   => 'debit',
                'fund_type'          => $session->fund_type,
                'amount_cents'       => $totalAmountCents,
                'fee_cents'          => $feeCents,
                'currency'           => $invoice->currency,
                'gateway_response'   => $response->raw,
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

        if ((string) $invoice->pms_source === 'custom') {
            $webhookClient = Client::query()->where('pms_client_id', $invoice->pms_client_id)->first();
            if (! empty($webhookClient?->webhook_url)) {
                DispatchCustomWebhookJob::dispatch($invoice->id, 'invoice.paid');
            }
        }

        return $transaction;
    }

    /**
     * Resolve the QB multi-MID route for this invoice, if configured.
     * Returns the matching ClientMidRoute or null if multi-MID is not enabled
     * or no matching route is configured.
     */
    private function resolveQbMidRoute(
        Invoice $invoice,
        ?Client $client,
        string  $gateway
    ): ?ClientMidRoute {
        if (! $client || ! $client->qb_multi_mid_enabled) {
            return null;
        }

        if ($client->qb_fee_override_enabled) {
            // Read the per-invoice QB field to determine route
            $fieldName  = (string) ($client->qb_fee_override_field ?? 'Cash Discount');
            $fieldValue = $this->extractQbCustomField($invoice, $fieldName);

            if ($fieldValue === null) {
                $routeType = $client->fee_surcharge_enabled ? 'fees_on' : 'fees_off';
            } elseif (strtolower($fieldValue) === 'yes') {
                $routeType = 'fees_on';
            } else {
                $routeType = 'fees_off';
            }
        } else {
            // Override disabled — use client-level default for all invoices
            $routeType = $client->fee_surcharge_enabled ? 'fees_on' : 'fees_off';
        }

        return ClientMidRoute::query()
            ->where('client_id',  $client->id)
            ->where('route_type', $routeType)
            ->where('gateway',    strtolower($gateway))
            ->where('is_active',  true)
            ->first();
    }

    /**
     * Extract a QuickBooks invoice custom field value by name.
     * QB stores custom fields as: CustomField[{Name: "...", StringValue: "..."}]
     */
    private function extractQbCustomField(Invoice $invoice, string $fieldName): ?string
    {
        $payload = (array) ($invoice->raw_payload ?? []);

        // Real QB invoices: raw_payload['invoice']['Invoice']['CustomField']
        // Manually created test invoices: raw_payload['CustomField']
        $fields = $payload['invoice']['Invoice']['CustomField']
            ?? $payload['CustomField']
            ?? $payload['custom_field']
            ?? [];

        if (! is_array($fields)) {
            return null;
        }

        foreach ($fields as $field) {
            $name  = $field['Name'] ?? $field['name'] ?? '';
            $value = $field['StringValue'] ?? $field['string_value'] ?? $field['value'] ?? null;
            if (strcasecmp((string) $name, $fieldName) === 0 && $value !== null) {
                return (string) $value;
            }
        }

        return null;
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

        $pausedGateways = collect($client?->paused_payment_gateways ?? [])
            ->map(fn ($gateway) => strtolower((string) $gateway))
            ->filter()
            ->values();

        if ($allowedGateways->isEmpty()) {
            return $options;
        }

        return $options
            ->filter(fn ($decision) =>
                $allowedGateways->contains(strtolower((string) $decision->gateway)) &&
                ! $pausedGateways->contains(strtolower((string) $decision->gateway))
            )
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
        // Custom and Wave always use static ACH credentials — no customer fields required.
        if (in_array((string) $invoice->pms_source, ['custom', 'wave'], true)) {
            return ['available' => true, 'reason' => null];
        }

        $details = $this->resolvePayaBankDetails($invoice);

        if ($details['missing'] !== []) {
            $refreshed = $this->refreshCustomerPayload($invoice);

            if ($refreshed !== null) {
                $refreshed->save();
                $details = $this->resolvePayaBankDetails($refreshed);
            }
        }

        if ($details['missing'] !== []) {
            // Fall back to env-configured default ACH credentials before marking unavailable
            $defaultRouting = env('PAYA_ROUTING_NUMBER', '');
            $defaultAccount = env('PAYA_ACCOUNT_NUMBER', '');

            if ($defaultRouting !== '' && $defaultAccount !== '') {
                return ['available' => true, 'reason' => null];
            }

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
