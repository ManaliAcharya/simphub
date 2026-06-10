<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\MindbodySite;
use RuntimeException;

class MindbodyApiClient
{
    private const BASE_URL = 'https://api.mindbodyonline.com/public/v6';

    // ── Auth endpoints ─────────────────────────────────────────────────────

    public function issueStaffToken(string $siteId, string $username, string $password): array
    {
        $response = $this->publicRequest($siteId)
            ->post(self::BASE_URL . '/usertoken/issue', [
                'Username' => $username,
                'Password' => $password,
            ])
            ->throw();

        return $response->json();
    }

    public function renewStaffToken(string $siteId, string $currentToken): array
    {
        $response = $this->authRequest($siteId, $currentToken)
            ->post(self::BASE_URL . '/usertoken/renew')
            ->throw();

        return $response->json();
    }

    // ── Client endpoints ───────────────────────────────────────────────────

    public function fetchClient(MindbodySite $site, string $clientId): array
    {
        $response = $this->authRequest($site->site_id, $site->staff_token)
            ->get(self::BASE_URL . '/client/clients', [
                'ClientIds' => $clientId,
            ])
            ->throw();

        $clients = $response->json('Clients', []);

        if (empty($clients)) {
            throw new RuntimeException("Mindbody client {$clientId} not found.");
        }

        return $clients[0];
    }

    public function fetchAccountBalance(MindbodySite $site, string $clientId): float
    {
        $response = $this->authRequest($site->site_id, $site->staff_token)
            ->get(self::BASE_URL . '/client/clientaccountbalances', [
                'ClientIds' => $clientId,
            ])
            ->throw();

        $balances = $response->json('Clients', []);

        if (empty($balances)) {
            return 0.0;
        }

        return (float) ($balances[0]['AccountBalance'] ?? 0.0);
    }

    // ── Sale endpoints ─────────────────────────────────────────────────────

    public function fetchSale(MindbodySite $site, string $saleId): array
    {
        $response = $this->authRequest($site->site_id, $site->staff_token)
            ->get(self::BASE_URL . '/sale/sales', [
                'SaleId' => $saleId,
            ])
            ->throw();

        $sales = $response->json('Sales', []);

        if (empty($sales)) {
            throw new RuntimeException("Mindbody sale {$saleId} not found.");
        }

        return $sales[0];
    }

    public function fetchCustomPaymentMethods(MindbodySite $site): array
    {
        $response = $this->authRequest($site->site_id, $site->staff_token)
            ->get(self::BASE_URL . '/sale/custompaymentmethods')
            ->throw();

        return $response->json('CustomPaymentMethods', []);
    }

    public function fetchPaymentTypes(MindbodySite $site): array
    {
        $response = $this->publicRequest($site->site_id)
            ->get(self::BASE_URL . '/sale/paymenttypes')
            ->throw();

        return $response->json('PaymentTypes', []);
    }

    /**
     * Post a payment against a Mindbody sale using the configured custom payment method.
     * Items: pass the original sale's line items so Mindbody links the payment to them.
     * If items are empty we send an empty array (records against client balance only).
     */
    public function postPayment(
        MindbodySite $site,
        string $clientId,
        float $amount,
        array $items = [],
        bool $sendEmail = false,
    ): array {
        $payload = [
            'ClientId'  => $clientId,
            'Test'      => app()->environment('production') ? false : true,
            'Items'     => $items,
            'Payments'  => [[
                'Type'                  => 'Custom',
                'CustomPaymentMethodId' => $site->custom_payment_method_id,
                'Amount'                => $amount,
            ]],
            'SendEmail' => $sendEmail,
        ];

        $response = $this->authRequest($site->site_id, $site->staff_token)
            ->post(self::BASE_URL . '/sale/checkoutshoppingcart', $payload)
            ->throw();

        return $response->json();
    }

    // ── Activation ─────────────────────────────────────────────────────────

    public function fetchActivationCode(string $siteId): string
    {
        $response = $this->publicRequest($siteId)
            ->get(self::BASE_URL . '/site/activationcode')
            ->throw();

        return (string) ($response->json('ActivationCode') ?? '');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function publicRequest(string $siteId): PendingRequest
    {
        return Http::withHeaders([
            'API-Key' => $this->apiKey(),
            'SiteId'  => $siteId,
        ])->acceptJson()->asJson();
    }

    private function authRequest(string $siteId, string $token): PendingRequest
    {
        return Http::withHeaders([
            'API-Key'       => $this->apiKey(),
            'SiteId'        => $siteId,
            'Authorization' => "Bearer {$token}",
        ])->acceptJson()->asJson();
    }

    private function apiKey(): string
    {
        $key = (string) config('services.mindbody.api_key', '');

        if ($key === '') {
            throw new RuntimeException('MINDBODY_API_KEY is not configured.');
        }

        return $key;
    }
}
