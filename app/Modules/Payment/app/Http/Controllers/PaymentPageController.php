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
        $logoUrl   = null;
        $feeConfig = null;

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
                    $logoUrl   = $client->logo_path ? '/storage/' . $client->logo_path : null;
                    $feeConfig = $this->buildFeeConfig($client, $invoice);
                }
            }
        }

        return view('payment::checkout', [
            'sessionToken' => $session,
            'logoUrl'      => $logoUrl,
            'feeConfig'    => $feeConfig,
        ]);
    }

    private function buildFeeConfig(Client $client, Invoice $invoice): array
    {
        $feeEnabled  = (bool) $client->fee_surcharge_enabled;
        $feeMode     = $client->fee_mode ?? 'surcharge';
        $gatewayRates = null;   // null = use cc/ach fallback
        $routeType   = null;

        // ── QB Multi-MID: when ON, Cash Discount field drives fees + MID routing ────
        // When OFF, fee_surcharge_enabled default applies (no field reading).
        if ($client->qb_multi_mid_enabled && (string) $invoice->pms_source === 'quickbooks') {
            $fieldName  = (string) ($client->qb_fee_override_field ?? 'Cash Discount');
            $fieldValue = $this->extractQbField($invoice, $fieldName);

            if ($fieldValue === null) {
                // Field not set → use Processing fees (cc/ach), no MID route override
                $routeType = null;
            } elseif (strtolower($fieldValue) === 'yes') {
                $routeType = 'fees_on';
            } else {
                $routeType = 'fees_off';
            }
            // feeEnabled unchanged when null (stays at fee_surcharge_enabled)

        }

        // ── Load per-gateway rates from MID routes ────────────────────────────────
        if ($client->qb_multi_mid_enabled && (string) $invoice->pms_source === 'quickbooks'
            && $routeType !== null) {

            $midRoutes = ClientMidRoute::query()
                ->where('client_id',  $client->id)
                ->where('route_type', $routeType)
                ->where('is_active',  true)
                ->get();

            if ($midRoutes->isNotEmpty()) {
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
