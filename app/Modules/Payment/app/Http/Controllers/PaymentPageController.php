<?php

namespace Modules\Payment\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClientMidRoute;

class PaymentPageController extends Controller
{
    public function show(string $session): View
    {
        $logoUrl    = null;
        $feeConfig  = null;
        $clientName = null;

        $paymentSession = PaymentSession::query()
            ->where('hosted_url_token', $session)
            ->first();

        if ($paymentSession) {
            $invoice = $paymentSession->invoice;
            if ($invoice && $invoice->pms_client_id) {
                $client = Client::query()
                    ->where('pms_client_id', $invoice->pms_client_id)
                    ->first();

                if ($client) {
                    $logoUrl    = $client->logo_path ? '/storage/' . $client->logo_path : null;
                    $clientName = $client->client_name;
                    $feeConfig  = $this->buildFeeConfig($client, $invoice);

                    // Pass only public/non-sensitive credential fields to the frontend tokenizer.
                    // Private keys (api_key, password, security_key) must NEVER reach the browser.
                    $feeConfig['gateway_credentials'] = $this->publicCredentialsOnly(
                        (array) ($client->gateway_credentials ?? [])
                    );
                }
            }
        }

        return view('payment::checkout', [
            'sessionToken' => $session,
            'logoUrl'      => $logoUrl,
            'feeConfig'    => $feeConfig,
            'clientName'   => $clientName,
        ]);
    }

    private function buildFeeConfig(Client $client, Invoice $invoice): array
    {
        $feeEnabled  = (bool) $client->fee_surcharge_enabled;
        $feeMode     = $client->fee_mode ?? 'surcharge';
        $gatewayRates = null;   // null = use cc/ach fallback
        $routeType   = null;

        $isQuickBooksInvoice = (string) $invoice->pms_source === 'quickbooks';

        if ($isQuickBooksInvoice) {
            if (! $client->qb_fee_override_enabled) {
                // Per-Invoice Fee Override is off → never show/apply a fee for QuickBooks
                // invoices, on any gateway/payment method, regardless of Processing Fee
                // Configuration.
                $feeEnabled = false;
            } else {
                // Override is ON → the per-invoice field (Yes/No) decides directly. Not set →
                // falls back to the client-level Processing Fee Configuration. $routeType is
                // only used below to further refine per-gateway rates when Multi-MID is ALSO on.
                $fieldName  = (string) ($client->qb_fee_override_field ?? 'Cash Discount');
                $fieldValue = $this->extractQbField($invoice, $fieldName);

                if ($fieldValue !== null) {
                    $feeEnabled = strtolower($fieldValue) === 'yes';
                    $routeType  = $feeEnabled ? 'fees_on' : 'fees_off';
                }
                // field not set → feeEnabled stays at fee_surcharge_enabled, routeType stays null
            }
        }

        // ── Load per-gateway rates from MID routes ────────────────────────────────
        if ($client->qb_fee_override_enabled && $client->qb_multi_mid_enabled && $isQuickBooksInvoice
            && $routeType !== null) {

            $midRoutes = ClientMidRoute::query()
                ->where('client_id',  $client->id)
                ->where('route_type', $routeType)
                ->where('is_active',  true)
                ->get();

            if ($routeType === 'fees_off') {
                // "No" means the merchant absorbs it, full stop — never show/apply a rate
                // here, even if a fees_off route mistakenly has rate_percent set on it.
                $feeEnabled = false;
                if ($midRoutes->isNotEmpty()) {
                    $gatewayRates = [];
                    foreach ($midRoutes as $route) {
                        $gatewayRates[strtolower($route->gateway)] = 0.0;
                    }
                }
            } elseif ($midRoutes->isNotEmpty()) {
                $gatewayRates = [];
                foreach ($midRoutes as $route) {
                    $gatewayRates[strtolower($route->gateway)] = (float) ($route->rate_percent ?? 0);
                }
                // feeEnabled = true if at least one gateway has a rate > 0
                $feeEnabled = collect($gatewayRates)->contains(fn($r) => $r > 0);
            } else {
                $feeEnabled = false; // no routes configured → flat
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        return [
            'client_name'            => $client->client_name,
            'fee_surcharge_enabled'  => $feeEnabled,
            'fee_mode'               => $feeMode,
            'gateway_rates'          => $gatewayRates,      // per-gateway %, null = use cc/ach
            'cc_fee_percent'         => (float) ($client->cc_fee_percent ?? 0),
            'ach_fee_percent'        => (float) ($client->ach_fee_percent ?? 0),
            'fee_disclosure'         => $client->fee_disclosure,
            'cash_discount_details'  => (array) ($client->cash_discount_details ?? []),
            'qb_route_type'          => $routeType,         // 'fees_on' | 'fees_off' | null
        ];
    }

    /**
     * Strip private/secret credential fields before sending to the browser.
     * Only public keys and environment settings are safe for client-side use.
     */
    private function publicCredentialsOnly(array $gwCreds): array
    {
        // Top-level safe keys (not per-gateway secrets)
        $safe = [];
        if (isset($gwCreds['environment'])) {
            $safe['environment'] = $gwCreds['environment'];
        }

        // Per-gateway: only expose public_key and environment; never api_key / password / security_key
        $allowedPerGateway = ['public_key', 'environment', 'tokenizer_url'];

        foreach (['fluidpay', 'paya', 'nmi'] as $gw) {
            if (isset($gwCreds[$gw]) && is_array($gwCreds[$gw])) {
                $filtered = array_intersect_key($gwCreds[$gw], array_flip($allowedPerGateway));
                if (! empty($filtered)) {
                    $safe[$gw] = $filtered;
                }
            }
        }

        return $safe;
    }

    /**
     * Extract a QuickBooks invoice custom field value by name.
     */
    private function extractQbField(Invoice $invoice, string $fieldName): ?string
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
}
