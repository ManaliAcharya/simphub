<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\Log;
use Modules\Inbound\Models\MindbodySaleLink;
use RuntimeException;

class MindbodyCustomerRefreshService
{
    public function __construct(
        private readonly MindbodyAuthService $auth,
        private readonly MindbodyApiClient $api,
    ) {}

    /**
     * Record the collected payment back to Mindbody via the custom payment method.
     * Called after a successful gateway charge.
     *
     * $totalChargedCents includes any surcharge/fee.
     * We post the invoice face value (sale_amount_cents) to Mindbody —
     * the surcharge is our revenue and should NOT inflate the Mindbody balance.
     */
    public function postPayment(
        MindbodySaleLink $link,
        string $gatewayTxnId,
        string $gateway,
        int $totalChargedCents,
    ): void {
        if ($link->isPaid() && $link->mb_payment_posted) {
            Log::info('Mindbody: payment already posted, skipping', ['sale_link_id' => $link->id]);
            return;
        }

        $site = $link->site;

        if (! $site?->custom_payment_method_id) {
            throw new RuntimeException('Mindbody custom payment method is not configured for this site.');
        }

        try {
            $site = $this->auth->ensureValidToken($site);

            // Post the sale's face value (not the fee-inclusive total) to Mindbody
            $amountDollars = round($link->sale_amount_cents / 100, 2);

            // Build items from raw sale payload for per_sale mode
            $items = $this->buildItems($link);

            $this->api->postPayment(
                site: $site,
                clientId: $link->client_id_mb,
                amount: $amountDollars,
                items: $items,
                sendEmail: false,
            );

            $link->forceFill([
                'payment_status'         => 'paid',
                'gateway'                => $gateway,
                'gateway_transaction_id' => $gatewayTxnId,
                'total_charged_cents'    => $totalChargedCents,
                'mb_payment_posted'      => true,
                'mb_payment_posted_at'   => now(),
            ])->save();

            Log::info('Mindbody: payment posted successfully', [
                'sale_id'        => $link->sale_id,
                'site_id'        => $site->site_id,
                'amount_dollars' => $amountDollars,
                'gateway_txn_id' => $gatewayTxnId,
            ]);
        } catch (\Throwable $e) {
            $link->forceFill([
                'payment_status'         => 'paid',          // gateway collected — mark paid
                'gateway'                => $gateway,
                'gateway_transaction_id' => $gatewayTxnId,
                'total_charged_cents'    => $totalChargedCents,
                'mb_payment_posted'      => false,            // but write-back failed
            ])->save();

            Log::error('Mindbody: payment write-back failed', [
                'sale_link_id' => $link->id,
                'sale_id'      => $link->sale_id,
                'error'        => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Build the Items array for checkoutshoppingcart from the stored raw sale payload.
     * In account_balance mode we send an empty items array.
     * In per_sale mode we attempt to reconstruct the items from the sale data.
     *
     * TODO: This needs sandbox validation. If checkoutshoppingcart requires items,
     * populate from raw_sale_payload['eventData']['LineItems']. If it works without
     * items (just records against client balance), return [].
     */
    private function buildItems(MindbodySaleLink $link): array
    {
        if ($link->site?->payment_link_mode !== 'per_sale') {
            return [];
        }

        $payload   = (array) ($link->raw_sale_payload ?? []);
        $eventData = $payload['eventData'] ?? $payload;
        $lineItems = (array) ($eventData['LineItems'] ?? []);

        if (empty($lineItems)) {
            return [];
        }

        // Map Mindbody line items to checkoutshoppingcart format
        return array_map(function (array $item) {
            return [
                'Item' => [
                    'Type' => $item['Type'] ?? 'Service',
                    'Id'   => $item['Id'] ?? null,
                ],
                'Quantity' => $item['Quantity'] ?? 1,
            ];
        }, $lineItems);
    }
}
