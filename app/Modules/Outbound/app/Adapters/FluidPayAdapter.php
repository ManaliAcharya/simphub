<?php

namespace Modules\Outbound\Adapters;

use Illuminate\Support\Facades\Http;
use Modules\Outbound\Contracts\GatewayAdapterInterface;
use Modules\Outbound\DTOs\ChargeRequest;
use Modules\Outbound\DTOs\GatewayResponse;
use Modules\Outbound\DTOs\HostedFieldsConfig;
use Modules\Outbound\DTOs\RefundRequest;

class FluidPayAdapter implements GatewayAdapterInterface
{
    public function code(): string
    {
        return 'fluidpay';
    }

    public function charge(ChargeRequest $request): GatewayResponse
    {
        if ($request->token === '') {
            return GatewayResponse::declined('Missing payment token.');
        }

        $midCredentials = $request->midCredentials;

        $apiKey  = (string) ($midCredentials['api_key']  ?? env('FLUIDPAY_API_KEY', ''));
        $baseUrl = rtrim((string) ($midCredentials['base_url'] ?? env('FLUIDPAY_BASE_URL', 'https://sandbox.fluidpay.com')), '/');

        if ($apiKey === '') {
            return GatewayResponse::declined('FluidPay API key is not configured.');
        }

        $raw = Http::withHeaders(['Authorization' => $apiKey])
            ->timeout(180)
            ->post("{$baseUrl}/api/transaction", [
                'type'           => 'sale',
                'amount'         => $request->amountInCents,
                'currency'       => $request->currency ?: 'USD',
                'payment_method' => [
                    'token' => $request->token,
                ],
            ])
            ->throw()
            ->json();

        // Response shape: { status, msg, data: { id, response, response_code, ... } }
        $data         = $raw['data'] ?? $raw;
        $transactionId = (string) ($data['id'] ?? '');
        $responseCode  = (int)    ($data['response_code'] ?? 0);
        $responseText  = (string) ($data['response'] ?? $raw['msg'] ?? 'Unknown error');

        // response_code 100–199 are approvals per FluidPay docs
        if ($responseCode >= 100 && $responseCode <= 199) {
            return GatewayResponse::approved($transactionId, $request->token, $raw);
        }

        return GatewayResponse::declined(
            $responseText ?: "Transaction declined (code: {$responseCode})",
            null,
            $raw,
        );
    }

    public function refund(RefundRequest $request): GatewayResponse
    {
        if ($request->gatewayTxnId === '') {
            return GatewayResponse::declined('Missing gateway transaction ID for refund.');
        }

        $midCredentials = $request->midCredentials;
        $apiKey  = (string) ($midCredentials['api_key']  ?? env('FLUIDPAY_API_KEY', ''));
        $baseUrl = rtrim((string) ($midCredentials['base_url'] ?? env('FLUIDPAY_BASE_URL', 'https://sandbox.fluidpay.com')), '/');

        if ($apiKey === '') {
            return GatewayResponse::declined('FluidPay API key is not configured.');
        }

        $body = ['amount' => $request->amountInCents];

        $raw = Http::withHeaders(['Authorization' => $apiKey])
            ->timeout(180)
            ->post("{$baseUrl}/api/transaction/{$request->gatewayTxnId}/refund", $body)
            ->throw()
            ->json();

        $data         = $raw['data'] ?? $raw;
        $transactionId = (string) ($data['id'] ?? '');
        $responseCode  = (int) ($data['response_code'] ?? 0);
        $responseText  = (string) ($data['response'] ?? $raw['msg'] ?? 'Unknown error');

        if ($responseCode >= 100 && $responseCode <= 199) {
            return GatewayResponse::approved($transactionId, null, $raw);
        }

        return GatewayResponse::declined(
            $responseText ?: "Refund declined (code: {$responseCode})",
            null,
            $raw,
        );
    }

    public function hostedFieldsConfig(string $mid, array $midCredentials = []): HostedFieldsConfig
    {
        $publicKey = (string) ($midCredentials['public_key'] ?? env('FLUIDPAY_PUBLIC_KEY', ''));
        $baseUrl = (string) ($midCredentials['base_url'] ?? env('FLUIDPAY_BASE_URL', 'https://sandbox.fluidpay.com'));
        $tokenizerUrl = (string) ($midCredentials['tokenizer_url'] ?? env('FLUIDPAY_TOKENIZER_URL', rtrim($baseUrl, '/').'/tokenizer/tokenizer.js'));

        return new HostedFieldsConfig(
            gateway: 'fluidpay',
            fields: [
                'script_url' => $tokenizerUrl,
                'container' => '#fluidpay-payment-form',
                'button_label' => 'Securely tokenize card with FluidPay',
            ],
            metadata: [
                'mid' => $mid,
                'base_url' => $baseUrl,
                'public_key' => $publicKey,
                'mode' => $publicKey !== '' ? 'tokenizer' : 'mock',
            ],
        );
    }
}
