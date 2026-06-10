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

        $apiKey  = (string) ($midCredentials['api_key'] ?? '');
        $baseUrl = ($midCredentials['environment'] ?? 'sandbox') === 'production'
            ? 'https://app.fluidpay.com'
            : rtrim((string) config('services.fluidpay.base_url', 'https://sandbox.fluidpay.com'), '/');

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
        $apiKey  = (string) ($midCredentials['api_key'] ?? '');
        $baseUrl = ($midCredentials['environment'] ?? 'sandbox') === 'production'
            ? 'https://app.fluidpay.com'
            : rtrim((string) config('services.fluidpay.base_url', 'https://sandbox.fluidpay.com'), '/');

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

    public function void(string $gatewayTxnId, array $midCredentials = []): GatewayResponse
    {
        if ($gatewayTxnId === '') {
            return GatewayResponse::declined('Missing gateway transaction ID for void.');
        }

        $apiKey  = (string) ($midCredentials['api_key'] ?? '');
        $baseUrl = ($midCredentials['environment'] ?? 'sandbox') === 'production'
            ? 'https://app.fluidpay.com'
            : rtrim((string) config('services.fluidpay.base_url', 'https://sandbox.fluidpay.com'), '/');

        if ($apiKey === '') {
            return GatewayResponse::declined('FluidPay API key is not configured.');
        }

        $raw = Http::withHeaders(['Authorization' => $apiKey])
            ->timeout(30)
            ->post("{$baseUrl}/api/transaction/{$gatewayTxnId}/void")
            ->throw()
            ->json();

        $data         = $raw['data'] ?? $raw;
        $transactionId = (string) ($data['id'] ?? $gatewayTxnId);
        $responseCode  = (int)    ($data['response_code'] ?? 0);
        $responseText  = (string) ($data['response'] ?? $raw['msg'] ?? 'Unknown error');

        if ($responseCode >= 100 && $responseCode <= 199) {
            return GatewayResponse::approved($transactionId, null, $raw);
        }

        return GatewayResponse::declined(
            $responseText ?: "Void declined (code: {$responseCode})",
            null,
            $raw,
        );
    }

    public function listTransactions(array $filters, array $midCredentials = []): array
    {
        $apiKey  = (string) ($midCredentials['api_key'] ?? '');
        $baseUrl = ($midCredentials['environment'] ?? 'sandbox') === 'production'
            ? 'https://app.fluidpay.com'
            : rtrim((string) config('services.fluidpay.base_url', 'https://sandbox.fluidpay.com'), '/');

        if ($apiKey === '') {
            return ['data' => [], 'total_count' => 0];
        }

        $body = array_filter([
            'limit'      => (int) ($filters['limit']      ?? 20),
            'offset'     => (int) ($filters['offset']     ?? 0),
            'start_date' => $filters['start_date'] ?? null,
            'end_date'   => $filters['end_date']   ?? null,
            'status'     => $filters['status']     ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $raw = Http::withHeaders(['Authorization' => $apiKey])
            ->timeout(30)
            ->post("{$baseUrl}/api/transaction/search", $body)
            ->throw()
            ->json();

        $rows = $raw['data'] ?? [];
        $total = (int) ($raw['total_count'] ?? count($rows));

        $data = array_map(fn (array $t) => [
            'gateway_txn_id' => (string) ($t['id']            ?? ''),
            'type'           => (string) ($t['type']          ?? ''),
            'status'         => (string) ($t['status']        ?? ''),
            'amount'         => (int)    ($t['amount']        ?? 0),
            'currency'       => (string) ($t['currency']      ?? 'usd'),
            'response_code'  => (int)    ($t['response_code'] ?? 0),
            'response'       => (string) ($t['response']      ?? ''),
            'created_at'     => (string) ($t['created_at']    ?? ''),
            'raw'            => $t,
        ], $rows);

        return ['data' => $data, 'total_count' => $total];
    }

    public function hostedFieldsConfig(string $mid, array $midCredentials = []): HostedFieldsConfig
    {
        $publicKey    = (string) ($midCredentials['public_key'] ?? '');
        $baseUrl      = ($midCredentials['environment'] ?? 'sandbox') === 'production'
            ? 'https://app.fluidpay.com'
            : 'https://sandbox.fluidpay.com';
        $tokenizerUrl = (string) ($midCredentials['tokenizer_url'] ?? ($baseUrl.'/tokenizer/tokenizer.js'));

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
