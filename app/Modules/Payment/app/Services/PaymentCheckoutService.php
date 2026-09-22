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
        $this->assertLinkActive($session);

        $invoice = $session->invoice()->firstOrFail();
        $options = $this->filterAllowedGateways(
            $this->routingCandidates($session),
            $invoice->pms_client_id,
        );

        if ($options->isEmpty()) {
            throw new RuntimeException('No routing rule available for this payment session.');
        }

        // Load client credentials once — needed to enrich hostedFieldsConfig
        // (e.g. FluidPay public_key for tokenizer iframe)
        $feeClient = $invoice->pms_client_id
            ? Client::query()->where('pms_client_id', $invoice->pms_client_id)->first()
            : null;

        try {
            $clientGwCreds = (array) ($feeClient?->gateway_credentials ?? []);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            $clientGwCreds = [];
        }

        $commonEnv = $clientGwCreds['environment'] ?? null;

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
                'billing_address'      => $this->resolveBillingAddressPrefill($invoice),
            ],
            'payment_options' => $options->map(function ($decision) use ($invoice, $clientGwCreds, $commonEnv, $feeClient) {
                $availability = strtolower((string) $decision->gateway) === 'paya'
                    ? $this->payaAvailability($invoice)
                    : ['available' => true, 'reason' => null];

                // Merge client-specific credentials so hostedFieldsConfig gets
                // the correct public_key / environment for the tokenizer
                $gwCreds = $clientGwCreds[strtolower($decision->gateway)] ?? [];
                if ($commonEnv) {
                    $gwCreds['environment'] = $commonEnv;
                }
                $resolvedCreds = ! empty($gwCreds)
                    ? array_merge($decision->midCredentials, $gwCreds)
                    : $decision->midCredentials;

                $hostedFields = $this->gateways->make($decision->gateway)->hostedFieldsConfig(
                    $decision->mid,
                    $resolvedCreds,
                );

                return [
                    'routing_rule_id' => $decision->routingRuleId,
                    'gateway' => $decision->gateway,
                    'display_name' => $feeClient
                        ? $feeClient->gatewayDisplayName($decision->gateway)
                        : strtoupper($decision->gateway),
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

    /**
     * Prefill for the checkout page's (editable) billing address fields — reuses
     * the same Invoice.BillAddr already stored in raw_payload for the PDF
     * (InvoicePdfService::resolveBillingAddress). Only QuickBooks invoices have
     * this shape in raw_payload; every other PMS falls through to blanks, same
     * as the PDF does. Falls back field-by-field to ShipAddr for whichever of
     * address1/city/state/zip BillAddr doesn't have — better a mostly-filled
     * prefill than an empty one, since the customer can still edit any of it.
     */
    /**
     * Exact-decimal surcharge/cash-discount fee calculation for IOLTA-compliant clients: the
     * invoice amount must deposit to the exact cent, so this cannot use float math (binary
     * floating point can't represent most decimal fractions exactly) or round the fee itself
     * (the client's rate is deliberately a few thousandths off the processor's flat rate so
     * that, after rounding the TOTAL up to the next cent, the processor's cut lands the
     * remainder back to precisely the invoice amount). All arithmetic is bcmath on decimal
     * strings; $feePercent may carry up to 5 decimal places (e.g. "3.48753").
     *
     *   rate_fraction   = feePercent / 100
     *   fee_cents_exact = invoiceCents * rate_fraction
     *   total_exact     = invoiceCents + fee_cents_exact
     *   total_cents     = ceil(total_exact)        // always rounds up, never nearest/down
     */
    private function calculateExactFeeCents(int $invoiceCents, string $feePercent): int
    {
        $scale = 15; // headroom before the final whole-cent ceiling step

        $rateFraction  = bcdiv($feePercent, '100', $scale);
        $feeCentsExact = bcmul((string) $invoiceCents, $rateFraction, $scale);
        $totalExact    = bcadd((string) $invoiceCents, $feeCentsExact, $scale);
        $totalCents    = $this->ceilDecimalToInt($totalExact);

        return $totalCents - $invoiceCents;
    }

    /**
     * Ceiling for a non-negative decimal string via bcmath (no float involved). bcmath's scale
     * reduction truncates rather than rounds, so this bumps the truncation up by one whenever a
     * fractional remainder was dropped.
     */
    private function ceilDecimalToInt(string $decimal): int
    {
        $truncated = bcadd($decimal, '0', 0);

        if (bccomp($decimal, $truncated, 15) > 0) {
            $truncated = bcadd($truncated, '1', 0);
        }

        return (int) $truncated;
    }

    private function resolveBillingAddressPrefill(Invoice $invoice): array
    {
        $customerName = trim((string) data_get($invoice->raw_payload, 'invoice.Invoice.CustomerRef.name', ''));

        $billAddr = $this->extractQboAddressFields(data_get($invoice->raw_payload, 'invoice.Invoice.BillAddr'), $customerName);
        $shipAddr = $this->extractQboAddressFields(data_get($invoice->raw_payload, 'invoice.Invoice.ShipAddr'), $customerName);

        return [
            'address1' => $billAddr['address1'] !== '' ? $billAddr['address1'] : $shipAddr['address1'],
            'city'     => $billAddr['city']     !== '' ? $billAddr['city']     : $shipAddr['city'],
            'state'    => $billAddr['state']    !== '' ? $billAddr['state']    : $shipAddr['state'],
            'zip'      => $billAddr['zip']      !== '' ? $billAddr['zip']      : $shipAddr['zip'],
        ];
    }

    /**
     * QuickBooks' PhysicalAddress shape into flat address1/city/state/zip.
     * Skips Line1 when it's just the customer's own name — QuickBooks defaults
     * it there when no street address is on file — falling back to Line2.
     */
    private function extractQboAddressFields(mixed $addr, string $customerName): array
    {
        if (! is_array($addr)) {
            return ['address1' => '', 'city' => '', 'state' => '', 'zip' => ''];
        }

        $line1 = trim((string) ($addr['Line1'] ?? ''));
        if ($line1 !== '' && $customerName !== '' && strcasecmp($line1, $customerName) === 0) {
            $line1 = trim((string) ($addr['Line2'] ?? ''));
        }

        return [
            'address1' => $line1,
            'city'     => (string) ($addr['City'] ?? ''),
            'state'    => (string) ($addr['CountrySubDivisionCode'] ?? ''),
            'zip'      => (string) ($addr['PostalCode'] ?? ''),
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
        $this->assertLinkActive($session);

        $invoice = $session->invoice()->firstOrFail();

        // ── Fee surcharge ──────────────────────────────────────────────────
        $feeCents  = 0;
        $feeClient = $invoice->pms_client_id
            ? Client::query()->where('pms_client_id', $invoice->pms_client_id)->first()
            : null;

        $applyFee = $feeClient && $feeClient->fee_surcharge_enabled;

        $isQuickBooksInvoice = (string) $invoice->pms_source === 'quickbooks';

        if ($feeClient && $isQuickBooksInvoice) {
            if (! $feeClient->qb_fee_override_enabled) {
                // Per-Invoice Fee Override is off → never charge a fee on QuickBooks invoices,
                // on any gateway/payment method, regardless of Processing Fee Configuration.
                $applyFee = false;
            } else {
                // Override is ON → the per-invoice field decides directly, via the standard
                // cc/ach percentages below. Only an exact "Yes" charges a fee — No, a typo, or
                // the field being unset all mean the merchant absorbs it, so a data-entry mistake
                // never accidentally charges a customer. Multi-MID Routing (if also enabled) can
                // still replace this with a per-gateway rate further down — see resolveQbMidRoute().
                $fieldName  = (string) ($feeClient->qb_fee_override_field ?? 'Cash Discount');
                $fieldValue = $this->extractQbCustomField($invoice, $fieldName);

                $applyFee = $fieldValue !== null && strtolower(trim($fieldValue)) === 'yes';
            }
        }

        if ($applyFee) {
            $isAch         = strtoupper($paymentMethod) === 'ACH';
            $feePercentRaw = $isAch ? $feeClient->ach_fee_percent : $feeClient->cc_fee_percent;
            $feePercent    = $feePercentRaw !== null ? (string) $feePercentRaw : '0';
            if (bccomp($feePercent, '0', 10) > 0) {
                $feeCents = $this->calculateExactFeeCents((int) $invoice->amount_cents, $feePercent);
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

        // ── Client-specific gateway credentials override ───────────────────────
        // If the client has their own credentials for this gateway, merge them
        // on top of the routing rule defaults (only non-empty values are stored).
        if ($feeClient) {
            try {
                $clientCreds = (array) ($feeClient->gateway_credentials ?? []);
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                \Log::error('Gateway credentials decryption failed', [
                    'pms_client_id' => $feeClient->pms_client_id,
                    'gateway'       => $decision->gateway,
                ]);
                throw new RuntimeException('Gateway credentials are corrupted. Please re-enter them in the client config.');
            }
            $gwCreds     = $clientCreds[strtolower($decision->gateway)] ?? [];
            // Inject the common environment into per-gateway credentials
            $commonEnv = $clientCreds['environment'] ?? null;
            if ($commonEnv) {
                $gwCreds['environment'] = $commonEnv;
            }
            if (! empty($gwCreds)) {
                $decision = new \Modules\Routing\DTOs\RoutingDecision(
                    gateway:        $decision->gateway,
                    mid:            $decision->mid,
                    routingRuleId:  $decision->routingRuleId,
                    midCredentials: array_merge($decision->midCredentials, $gwCreds),
                    ruleMatches:    $decision->ruleMatches,
                );
            }
        }
        // ── Credential validation ──────────────────────────────────────────────
        // Verify that the resolved credentials (client-specific OR static defaults)
        // are sufficient for the selected gateway. Fails early with a clear message
        // rather than a cryptic gateway API error.
        $this->validateGatewayCredentials($decision->gateway, $decision->midCredentials);
        // ──────────────────────────────────────────────────────────────────────

        AuditLogger::log('ROUTING_DECISION_MADE', 'payment_session', $session->id, [
            'gateway' => $decision->gateway,
            'mid' => $decision->mid,
            'routing_rule_id' => $decision->routingRuleId,
        ]);

        // Payer's first/last name is required to fully process a sale on every gateway
        // (card or ACH); billing address is optional. Captured on the checkout page and
        // persisted onto the transaction/sale record below.
        $cardholderFirstName = trim((string) ($extraBilling['first_name'] ?? '')) ?: null;
        $cardholderLastName  = trim((string) ($extraBilling['last_name'] ?? '')) ?: null;
        $billingAddress      = ! empty($extraBilling['billing_address']) ? $extraBilling['billing_address'] : null;

        if (! $cardholderFirstName || ! $cardholderLastName) {
            throw new RuntimeException('Payer first and last name are required to process this payment.');
        }

        $billing = [];
        if (strtolower((string) $decision->gateway) === 'paya') {
            if (! empty($extraBilling) && isset($extraBilling['paya_bank_token'])) {
                $payaBankToken = (string) $extraBilling['paya_bank_token'];
                // Custom bank-entry form: token is Laravel-encrypted JSON with routing/account.
                // Paya iframe AccountForm: token is an actual Paya vault ID (cannot decrypt).
                // Try to decrypt — if it succeeds, use direct ACH; otherwise treat as vault token.
                try {
                    $bankDetails = $this->payaTokenizer->detokenize($payaBankToken);
                    $billing = [
                        'routing_number' => (string) ($bankDetails['routing_number'] ?? ''),
                        'account_number' => (string) ($bankDetails['account_number'] ?? ''),
                    ];
                } catch (\Throwable) {
                    $billing = ['paya_token' => $payaBankToken];
                }
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
        } elseif ($cardholderFirstName && $cardholderLastName) {
            // Card gateways (FluidPay/NMI): pass the cardholder name (+ optional billing
            // address) through to the processor so the sale is fully processed.
            $billing = array_filter([
                'first_name' => $cardholderFirstName,
                'last_name'  => $cardholderLastName,
                'address'    => $billingAddress,
            ]);
        }

        // ── QB Multi-MID override ──────────────────────────────────────────
        $qbMidRoute = null;
        if ($invoice->pms_client_id && (string) $invoice->pms_source === 'quickbooks') {
            $qbMidRoute = $this->resolveQbMidRoute($invoice, $feeClient, $decision->gateway);
        }
        if ($qbMidRoute) {
            // RoutingDecision is readonly — replace with new instance using overridden MID.
            // Base = original routing rule credentials (keeps namespace, wsdl, method names etc.)
            // Override only the fields stored in the MID route (api_key, username/password etc.)
            $overrideCredentials = array_merge(
                $decision->midCredentials,                       // base: routing rule defaults
                array_filter((array) ($qbMidRoute->credentials ?? []), fn($v) => $v !== null && $v !== ''),
                ['environment' => $qbMidRoute->environment ?? 'sandbox'],
                // processor_id lives on the MID route itself (plain column, not the encrypted
                // credentials blob) — required by FluidPay to pick the right processor when the
                // account has more than one MID; omitted when the MID doesn't have one set.
                $qbMidRoute->processor_id ? ['processor_id' => $qbMidRoute->processor_id] : [],
            );
            $decision = new \Modules\Routing\DTOs\RoutingDecision(
                gateway:        $decision->gateway,
                mid:            $qbMidRoute->mid_identifier,
                routingRuleId:  $decision->routingRuleId,
                midCredentials: $overrideCredentials,
                ruleMatches:    $decision->ruleMatches,
            );

            // Only the fees_on route can ever add a fee. fees_off means the custom field
            // said "No" — the merchant absorbs it, full stop — so the customer is never
            // charged from that route, even if rate_percent happens to be set on that row
            // (a misconfiguration we must not let leak through as a real charge).
            if ($qbMidRoute->route_type === 'fees_on'
                && $qbMidRoute->rate_percent !== null
                && (float) $qbMidRoute->rate_percent > 0) {
                $overridePercent  = (float) $qbMidRoute->rate_percent;
                $feeCents         = (int) round($invoice->amount_cents * $overridePercent / 100);
                $totalAmountCents = (int) $invoice->amount_cents + $feeCents;
            } else {
                $feeCents         = 0;
                $totalAmountCents = (int) $invoice->amount_cents;
            }
        }
        // ──────────────────────────────────────────────────────────────────

        \Log::debug('PaymentCheckoutService: dispatching charge', [
            'gateway'      => $decision->gateway,
            'mid'          => $decision->mid,
            'environment'  => $decision->midCredentials['environment'] ?? 'sandbox',
            'routing_rule_id' => $decision->routingRuleId,
            'payment_session_id' => $session->id,
            'invoice_id'   => $invoice->id,
            'pms_source'   => $invoice->pms_source,
            'amount'       => $invoice->amount_cents,
            'fee_cents'    => $feeCents,
            'total'        => $totalAmountCents,
            'qb_mid_route' => $qbMidRoute?->id,
            'billing_keys' => array_keys($billing),
        ]);

        try {
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
        } catch (\Throwable $e) {
            $session->forceFill(['status' => 'FAILED'])->save();
            $invoice->forceFill(['status' => 'FAILED', 'pms_sync_status' => 'FAILED'])->save();
            $this->idempotency->fail((string) $session->idempotency_key);
            \Log::error('PaymentCheckoutService: charge threw exception', [
                'gateway'      => $decision->gateway,
                'mid'          => $decision->mid,
                'environment'  => $decision->midCredentials['environment'] ?? 'sandbox',
                'routing_rule_id' => $decision->routingRuleId,
                'payment_session_id' => $session->id,
                'invoice_id'   => $invoice->id,
                'exception'    => get_class($e),
                'error'        => $e->getMessage(),
            ]);
            throw new RuntimeException('Payment could not be processed: '.$e->getMessage(), 0, $e);
        }

        \Log::debug('PaymentCheckoutService: charge response', [
            'gateway'      => $decision->gateway,
            'mid'          => $decision->mid,
            'payment_session_id' => $session->id,
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
                'message'     => $response->message,
                'gateway'     => $decision->gateway,
                'mid'         => $decision->mid,
                'environment' => $decision->midCredentials['environment'] ?? 'sandbox',
            ]);

            if ((string) $invoice->pms_source === 'custom') {
                $webhookClient = Client::query()->where('pms_client_id', $invoice->pms_client_id)->first();
                if (! empty($webhookClient?->webhook_url)) {
                    DispatchCustomWebhookJob::dispatch($invoice->id, 'invoice.failed');
                }
            }

            throw new RuntimeException($response->message ?? 'Payment was declined.');
        }

        $transaction = DB::transaction(function () use (
            $session, $invoice, $decision, $response, $feeCents, $totalAmountCents,
            $cardholderFirstName, $cardholderLastName, $billingAddress,
        ) {
            $transaction = Transaction::query()->create([
                'payment_session_id'    => $session->id,
                'invoice_id'            => $invoice->id,
                'routing_rule_id'       => $decision->routingRuleId,
                'gateway'               => $decision->gateway,
                'mid'                   => $decision->mid,
                'processor_id'          => $decision->midCredentials['processor_id'] ?? null,
                'gateway_txn_id'        => $response->transactionReference,
                'gateway_token'         => (string) $response->gatewayToken,
                'status'                => 'CAPTURED',
                'transaction_type'      => 'debit',
                'fund_type'             => $session->fund_type,
                'amount_cents'          => $totalAmountCents,
                'fee_cents'             => $feeCents,
                'currency'              => $invoice->currency,
                'gateway_response'      => $response->raw,
                'cardholder_first_name' => $cardholderFirstName,
                'cardholder_last_name'  => $cardholderLastName,
                'billing_address'       => $billingAddress,
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
        if (! $client || ! $client->qb_fee_override_enabled || ! $client->qb_multi_mid_enabled) {
            return null;
        }

        // Multi-MID ON → read the fee-override field. Only an exact "Yes" routes to the
        // fees_on MID — No, a typo, or the field being unset all route to fees_off, matching
        // the merchant-absorbs default applied above.
        $fieldName  = (string) ($client->qb_fee_override_field ?? 'Cash Discount');
        $fieldValue = $this->extractQbCustomField($invoice, $fieldName);

        $routeType = ($fieldValue !== null && strtolower(trim($fieldValue)) === 'yes') ? 'fees_on' : 'fees_off';

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

        // Check all possible locations — invoice-level first, then customer-level, then top-level (test)
        $candidates = [
            $payload['invoice']['Invoice']['CustomField'] ?? [],   // transaction-level field
            $payload['customer']['Customer']['CustomField'] ?? [],  // customer-level field
            $payload['CustomField'] ?? [],                          // manually created test invoices
        ];

        foreach ($candidates as $fields) {
            if (! is_array($fields) || empty($fields)) {
                continue;
            }
            foreach ($fields as $field) {
                $name  = $field['Name'] ?? $field['name'] ?? '';
                $value = $field['StringValue'] ?? $field['string_value'] ?? $field['value'] ?? null;
                if (strcasecmp((string) $name, $fieldName) === 0
                    && $value !== null
                    && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
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
        // Customer always enters routing/account on the checkout form — no pre-fetched
        // bank details required regardless of PMS source.
        return ['available' => true, 'reason' => null];
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

    /**
     * Verify that effective credentials (client-specific OR static defaults) are
     * sufficient for the gateway before attempting the charge.
     *
     * Priority: client gateway_credentials → routing rule mid_credentials → static env/config
     * The adapters apply the same fallback chain, so this mirrors their logic.
     */
    private function validateGatewayCredentials(string $gateway, array $midCredentials): void
    {
        $gateway = strtolower($gateway);

        if ($gateway === 'fluidpay') {
            if (trim((string) ($midCredentials['api_key'] ?? '')) === '') {
                \Log::error('PaymentCheckoutService: FluidPay credential validation failed', [
                    'environment' => $midCredentials['environment'] ?? 'sandbox',
                    'credential_keys' => array_keys($midCredentials),
                ]);
                throw new RuntimeException(
                    'FluidPay API key is not configured. Please set it in the Gateway Credentials or the routing rule.'
                );
            }
        }

        if ($gateway === 'paya') {
            if (trim((string) ($midCredentials['username']    ?? '')) === '' ||
                trim((string) ($midCredentials['terminal_id'] ?? '')) === '') {
                throw new RuntimeException(
                    'Paya credentials are not configured. Please set them in the Gateway Credentials or the routing rule.'
                );
            }
        }

        if ($gateway === 'nmi') {
            if (trim((string) ($midCredentials['security_key'] ?? '')) === '') {
                throw new RuntimeException(
                    'NMI security key is not configured. Please set it in the Gateway Credentials or the routing rule.'
                );
            }
        }
    }

    private function assertLinkActive(PaymentSession $session): void
    {
        $status = (string) ($session->link_status ?? 'active');

        if ($status === 'active') {
            return;
        }

        throw new RuntimeException(match ($status) {
            'voided'   => 'This invoice has been voided. The payment link is no longer valid.',
            'disabled' => 'This payment link has been disabled.',
            'paid'     => 'This invoice has already been paid.',
            default    => 'This payment link is no longer available.',
        });
    }
}
