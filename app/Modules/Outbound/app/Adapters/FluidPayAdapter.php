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

        $resolved = $this->resolveCredentials($request->midCredentials);
        ['api_key' => $apiKey, 'base_url' => $baseUrl, 'is_production' => $isProduction, 'source' => $credSource] = $resolved;

        if ($apiKey === '') {
            \Log::error('FluidPay charge aborted: API key not configured', [
                'environment'      => $isProduction ? 'production' : 'sandbox',
                'base_url'         => $baseUrl,
                'credential_source' => $credSource,
                'invoice_id'        => $request->metadata['invoice_id'] ?? null,
                'payment_session_id' => $request->metadata['payment_session_id'] ?? null,
                'routing_rule_id'   => $request->metadata['routing_rule_id'] ?? null,
            ]);
            return GatewayResponse::declined('FluidPay API key is not configured.');
        }

        $startedAt = microtime(true);

        // Accounts with more than one FluidPay MID need processor_id to pick which processor
        // the transaction runs on — the API key alone is account-scoped, not MID-scoped, and
        // FluidPay silently falls back to the account's default processor without it.
        $processorId = trim((string) ($request->midCredentials['processor_id'] ?? ''));

        \Log::debug('FluidPay charge attempt', [
            'environment'        => $isProduction ? 'production' : 'sandbox',
            'base_url'           => $baseUrl,
            'api_key_prefix'     => substr($apiKey, 0, 10).'...',
            'api_key_length'     => strlen($apiKey),
            'credential_source'  => $credSource,
            'processor_id'       => $processorId !== '' ? $processorId : null,
            'amount_cents'       => $request->amountInCents,
            'currency'           => $request->currency ?: 'USD',
            'transaction_type'   => $request->transactionType,
            'idempotency_key'    => $request->idempotencyKey,
            'invoice_id'         => $request->metadata['invoice_id'] ?? null,
            'payment_session_id' => $request->metadata['payment_session_id'] ?? null,
            'routing_rule_id'    => $request->metadata['routing_rule_id'] ?? null,
        ]);

        $payload = [
            'type'           => 'sale',
            'amount'         => $request->amountInCents,
            'currency'       => $request->currency ?: 'USD',
            'payment_method' => [
                'token' => $request->token,
            ],
        ];

        if ($processorId !== '') {
            $payload['processor_id'] = $processorId;
        }

        // Cardholder name is required for FluidPay to fully process the sale.
        // Billing address is optional and only included when the customer supplied one.
        $firstName = trim((string) ($request->billing['first_name'] ?? ''));
        $lastName  = trim((string) ($request->billing['last_name'] ?? ''));
        if ($firstName !== '' || $lastName !== '') {
            $payload['first_name'] = $firstName;
            $payload['last_name']  = $lastName;
        }

        $address = array_filter((array) ($request->billing['address'] ?? []));
        if (! empty($address)) {
            $payload['billing_address'] = array_filter([
                'address_line_1' => $address['address1'] ?? null,
                'city'           => $address['city'] ?? null,
                'state'          => $address['state'] ?? null,
                'zip'            => $address['zip'] ?? null,
            ]);
        }

        try {
            $httpResponse = Http::withHeaders(['Authorization' => $apiKey])
                ->timeout(45)
                ->post("{$baseUrl}/api/transaction", $payload);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            \Log::error('FluidPay charge connection error', [
                'environment' => $isProduction ? 'production' : 'sandbox',
                'base_url'    => $baseUrl,
                'elapsed_ms'  => (int) ((microtime(true) - $startedAt) * 1000),
                'error'       => $e->getMessage(),
            ]);
            throw $e;
        }

        $elapsedMs = (int) ((microtime(true) - $startedAt) * 1000);
        $raw = $httpResponse->json() ?? [];

        if (! $httpResponse->successful()) {
            $msg = (string) ($raw['msg'] ?? $raw['message'] ?? "FluidPay API error (HTTP {$httpResponse->status()})");
            \Log::error('FluidPay charge HTTP error', [
                'status'      => $httpResponse->status(),
                'environment' => $isProduction ? 'production' : 'sandbox',
                'base_url'    => $baseUrl,
                'credential_source' => $credSource,
                'api_key_prefix'    => substr($apiKey, 0, 10).'...',
                'msg'         => $msg,
                'body'        => $raw ?: $httpResponse->body(),
                'response_headers' => [
                    'request-id' => $httpResponse->header('X-Request-Id') ?: $httpResponse->header('Request-Id'),
                    'content-type' => $httpResponse->header('Content-Type'),
                ],
                'elapsed_ms'  => $elapsedMs,
                'invoice_id'         => $request->metadata['invoice_id'] ?? null,
                'payment_session_id' => $request->metadata['payment_session_id'] ?? null,
                'routing_rule_id'    => $request->metadata['routing_rule_id'] ?? null,
            ]);
            return GatewayResponse::declined($msg, null, $raw);
        }

        // Response shape: { status, msg, data: { id, response, response_code, ... } }
        $data          = $raw['data'] ?? $raw;
        $transactionId = (string) ($data['id'] ?? '');
        $responseCode  = (int)    ($data['response_code'] ?? 0);
        $responseText  = (string) ($data['response'] ?? $raw['msg'] ?? 'Unknown error');

        \Log::debug('FluidPay charge HTTP response', [
            'status'         => $httpResponse->status(),
            'response_code'  => $responseCode,
            'response_text'  => $responseText,
            'transaction_id' => $transactionId,
            'elapsed_ms'     => $elapsedMs,
        ]);

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

        ['api_key' => $apiKey, 'base_url' => $baseUrl] = $this->resolveCredentials($request->midCredentials);

        if ($apiKey === '') {
            return GatewayResponse::declined('FluidPay API key is not configured.');
        }

        $body = ['amount' => $request->amountInCents];

        $httpResponse = Http::withHeaders(['Authorization' => $apiKey])
            ->timeout(45)
            ->post("{$baseUrl}/api/transaction/{$request->gatewayTxnId}/refund", $body);

        $raw = $httpResponse->json() ?? [];

        if (! $httpResponse->successful()) {
            $msg = (string) ($raw['msg'] ?? $raw['message'] ?? "FluidPay API error (HTTP {$httpResponse->status()})");
            return GatewayResponse::declined($msg, null, $raw);
        }

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

        ['api_key' => $apiKey, 'base_url' => $baseUrl] = $this->resolveCredentials($midCredentials);

        if ($apiKey === '') {
            return GatewayResponse::declined('FluidPay API key is not configured.');
        }

        $httpResponse = Http::withHeaders(['Authorization' => $apiKey])
            ->timeout(30)
            ->post("{$baseUrl}/api/transaction/{$gatewayTxnId}/void");

        $raw = $httpResponse->json() ?? [];

        if (! $httpResponse->successful()) {
            $msg = (string) ($raw['msg'] ?? $raw['message'] ?? "FluidPay API error (HTTP {$httpResponse->status()})");
            return GatewayResponse::declined($msg, null, $raw);
        }

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

    /**
     * List the processors configured on a FluidPay merchant account (Manage → Processors),
     * so admin UIs can offer processor_id as a picker instead of free text. $merchantId is
     * the MID itself — "MID" is short for Merchant ID, and FluidPay's own processors
     * endpoint is keyed on merchant_id, so no separate self-lookup call is needed (and the
     * per-merchant transaction key isn't authorized for account self-lookup anyway — only
     * for /api/transaction*).
     */
    public function listProcessors(array $midCredentials, string $merchantId): array
    {
        ['api_key' => $apiKey, 'base_url' => $baseUrl] = $this->resolveCredentials($midCredentials);

        if ($apiKey === '') {
            return ['processors' => [], 'error' => 'FluidPay API key is not configured.'];
        }

        if ($merchantId === '') {
            return ['processors' => [], 'error' => 'Enter a MID Identifier first.'];
        }

        try {
            $processorsResponse = Http::withHeaders(['Authorization' => $apiKey])
                ->timeout(20)
                ->get("{$baseUrl}/api/merchant/{$merchantId}/processors");
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return ['processors' => [], 'error' => 'Could not reach FluidPay: '.$e->getMessage()];
        }

        if (! $processorsResponse->successful()) {
            return ['processors' => [], 'error' => 'Could not fetch processors for MID "'.$merchantId.'" (HTTP '.$processorsResponse->status().').'];
        }

        $rows = (array) ($processorsResponse->json('data') ?? []);

        $processors = array_map(fn (array $p) => [
            'id'     => (string) ($p['id']     ?? ''),
            'name'   => (string) ($p['name']   ?? ''),
            'status' => (string) ($p['status'] ?? ''),
        ], $rows);

        return ['processors' => $processors, 'error' => null];
    }

    public function listTransactions(array $filters, array $midCredentials = []): array
    {
        ['api_key' => $apiKey, 'base_url' => $baseUrl] = $this->resolveCredentials($midCredentials);

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

    private function resolveCredentials(array $midCredentials): array
    {
        $isProduction = ($midCredentials['environment'] ?? 'sandbox') === 'production';

        $baseUrl = $isProduction
            ? rtrim((string) config('services.fluidpay.base_url_production', 'https://app.fluidpay.com'), '/')
            : rtrim((string) config('services.fluidpay.base_url', 'https://sandbox.fluidpay.com'), '/');

        $apiKeyFallback = $isProduction
            ? config('services.fluidpay.api_key_production', '')
            : config('services.fluidpay.api_key', '');

        $hasOverride = trim((string) ($midCredentials['api_key'] ?? '')) !== '';

        return [
            'api_key'      => (string) ($midCredentials['api_key'] ?? $apiKeyFallback),
            'base_url'     => $baseUrl,
            'is_production' => $isProduction,
            'source'       => $hasOverride ? 'mid_credentials_override' : 'env_config_fallback',
        ];
    }

    public function hostedFieldsConfig(string $mid, array $midCredentials = []): HostedFieldsConfig
    {
        $resolved     = $this->resolveCredentials($midCredentials);
        $baseUrl      = $resolved['base_url'];
        $isProduction = $resolved['is_production'];

        $publicKeyFallback = $isProduction
            ? config('services.fluidpay.public_key_production', '')
            : config('services.fluidpay.public_key', '');
        $tokenizerUrlFallback = $isProduction
            ? config('services.fluidpay.tokenizer_url_production')
            : config('services.fluidpay.tokenizer_url');

        $publicKey    = (string) ($midCredentials['public_key'] ?? $publicKeyFallback);
        $tokenizerUrl = (string) ($midCredentials['tokenizer_url']
            ?? $tokenizerUrlFallback
            ?? ($baseUrl . '/tokenizer/tokenizer.js'));

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
