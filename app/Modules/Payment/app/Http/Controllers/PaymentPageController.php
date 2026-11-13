<?php

namespace Modules\Payment\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClientMidRoute;
use Modules\Payment\Services\CheckoutAuditTrail;
use Modules\Payment\Services\QbFeeOverride;

class PaymentPageController extends Controller
{
    public function show(string $session, CheckoutAuditTrail $audit): View
    {
        $logoUrl    = null;
        $feeConfig  = null;
        $clientName = null;
        // Ties every step of this page visit together in the audit log.
        $visitId    = (string) Str::uuid();

        $paymentSession = PaymentSession::query()
            ->where('hosted_url_token', $session)
            ->first();

        if ($paymentSession) {
            $audit->record($paymentSession, 'CHECKOUT_PAGE_OPENED', [
                'visit_id'     => $visitId,
                'referer_host' => parse_url((string) request()->headers->get('referer'), PHP_URL_HOST) ?: null,
            ]);

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
                        $this->fundCredentials($client, (string) $invoice->fund_type)
                    );
                }
            }
        }

        return view('payment::checkout', [
            'sessionToken' => $session,
            'logoUrl'      => $logoUrl,
            'feeConfig'    => $feeConfig,
            'clientName'   => $clientName,
            'visitId'      => $visitId,
        ]);
    }

    private function buildFeeConfig(Client $client, Invoice $invoice): array
    {
        $feeEnabled  = (bool) $client->fee_surcharge_enabled;
        $feeMode     = $client->fee_mode ?? 'surcharge';
        $gatewayRates = null;   // null = use cc/ach fallback
        $routeType   = null;

        $usesFeeOverride = QbFeeOverride::supports($invoice);

        if ($usesFeeOverride) {
            if (! $client->qb_fee_override_enabled) {
                // Per-Invoice Fee Override is off → Processing Fee Configuration applies to every
                // QuickBooks/Clio invoice, as for any other PMS ($feeEnabled already holds it).
                $feeEnabled = (bool) $client->fee_surcharge_enabled;
            } else {
                // Override is ON → the per-invoice field decides directly: "Yes" passes the fee,
                // "No" or a typo absorbs it, and a blank field follows the merchant's default
                // (see QbFeeOverride). $routeType is used below to further refine per-gateway
                // rates when Multi-MID is ALSO on.
                $feeEnabled = QbFeeOverride::passesFee($client, $invoice);
                $routeType  = $feeEnabled ? 'fees_on' : 'fees_off';
            }
        }

        // ── Load per-gateway rates from MID routes ────────────────────────────────
        if ($client->qb_fee_override_enabled && $client->qb_multi_mid_enabled && $usesFeeOverride
            && $routeType !== null) {

            $midRoutes = ClientMidRoute::query()
                ->where('client_id',  $client->id)
                ->where('fund_type',  QbFeeOverride::midRouteFundType($invoice))
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

        // The rates of this invoice's account tab (IOLTA for trust money at Lawcus/Clio).
        $ccFeePercent  = (float) ($client->feePercentFor('CARD', $invoice->fund_type) ?? 0);
        $achFeePercent = (float) ($client->feePercentFor('ACH', $invoice->fund_type) ?? 0);

        // An account tab with no fee (e.g. an interchange-plus IOLTA MID) shows no fee disclosure.
        $tabHasNoFee = $client->requiresIoltaMid() && $gatewayRates === null
            && $ccFeePercent <= 0 && $achFeePercent <= 0;
        if ($tabHasNoFee) {
            $feeEnabled = false;
        }

        return [
            'client_name'            => $client->client_name,
            'fee_surcharge_enabled'  => $feeEnabled,
            'fee_mode'               => $feeMode,
            'gateway_rates'          => $gatewayRates,      // per-gateway %, null = use cc/ach
            'cc_fee_percent'         => $ccFeePercent,
            'ach_fee_percent'        => $achFeePercent,
            'fee_disclosure'         => $tabHasNoFee ? null : $client->fee_disclosure,
            // Per-tab rates can leave one payment method fee-free; don't disclose a fee on it.
            'disclose_only_with_fee' => $client->requiresIoltaMid(),
            'cash_discount_details'  => $this->cashDetailsFor($client, $invoice),
            'qb_route_type'          => $routeType,         // 'fees_on' | 'fees_off' | null
        ];
    }

    /**
     * Cash/check details for this invoice's payment page. Lawcus/Clio keep separate
     * "Additional instructions" per account (e.g. separate Zelle IDs), so a trust page shows
     * only the IOLTA account's and an operating page only the operating one's.
     */
    private function cashDetailsFor(Client $client, Invoice $invoice): array
    {
        $details = (array) ($client->cash_discount_details ?? []);

        if ($client->requiresIoltaMid() && strtoupper((string) $invoice->fund_type) === 'TRUST') {
            $details['instructions'] = $details['trust_instructions'] ?? null;
        }

        unset($details['trust_instructions']);

        return array_filter($details, fn ($value) => $value !== null && $value !== '');
    }

    /** Gateway Credentials in their stored shape, with each gateway's IOLTA set for trust money. */
    private function fundCredentials(Client $client, string $fundType): array
    {
        $creds = (array) ($client->gateway_credentials ?? []);

        foreach (['fluidpay', 'paya', 'nmi'] as $gw) {
            $creds[$gw] = $client->gatewayCredentialsFor($gw, $fundType);
        }

        return $creds;
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
}
