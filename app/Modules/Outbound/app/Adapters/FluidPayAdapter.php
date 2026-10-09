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
                'correlation_id' => $this->correlationId($httpResponse),
                'content_type'   => $httpResponse->header('Content-Type'),
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

        \Log::info('FluidPay charge HTTP response', [
            'status'         => $httpResponse->status(),
            'environment'    => $isProduction ? 'production' : 'sandbox',
            'response_code'  => $responseCode,
            'response_text'  => $responseText,
            'transaction_id' => $transactionId,
            'correlation_id' => $this->correlationId($httpResponse),
            'elapsed_ms'     => $elapsedMs,
            'invoice_id'         => $request->metadata['invoice_id'] ?? null,
            'payment_session_id' => $request->metadata['payment_session_id'] ?? null,
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

        $startedAt = microtime(true);
        try {
            $httpResponse = Http::withHeaders(['Authorization' => $apiKey])
                ->timeout(45)
                ->post("{$baseUrl}/api/transaction/{$request->gatewayTxnId}/refund", $body);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->logConnectionError('refund', $baseUrl, $startedAt, $e, ['gateway_txn_id' => $request->gatewayTxnId]);
            throw $e;
        }

        $this->logApiCall('refund', $httpResponse, $baseUrl, $startedAt, [
            'gateway_txn_id' => $request->gatewayTxnId,
            'amount_cents'   => $request->amountInCents,
        ]);

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

        $startedAt = microtime(true);
        try {
            $httpResponse = Http::withHeaders(['Authorization' => $apiKey])
                ->timeout(30)
                ->post("{$baseUrl}/api/transaction/{$gatewayTxnId}/void");
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->logConnectionError('void', $baseUrl, $startedAt, $e, ['gateway_txn_id' => $gatewayTxnId]);
            throw $e;
        }

        $this->logApiCall('void', $httpResponse, $baseUrl, $startedAt, ['gateway_txn_id' => $gatewayTxnId]);

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
        $resolved = $this->resolveCredentials($midCredentials);
        ['api_key' => $apiKey, 'base_url' => $baseUrl, 'source' => $credSource] = $resolved;

        if ($apiKey === '') {
            return ['processors' => [], 'error' => 'FluidPay API key is not configured.'];
        }

        if ($merchantId === '') {
            return ['processors' => [], 'error' => 'Enter a MID Identifier first.'];
        }

        $keyHint = substr($apiKey, 0, 8).'...('.strlen($apiKey).' chars, source: '.$credSource.')';

        $logContext = [
            'merchant_id'       => $merchantId,
            'credential_source' => $credSource,
            'api_key_prefix'    => substr($apiKey, 0, 8).'...',
        ];

        $startedAt = microtime(true);
        try {
            $processorsResponse = Http::withHeaders(['Authorization' => $apiKey])
                ->timeout(20)
                ->get("{$baseUrl}/api/merchant/{$merchantId}/processors");
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->logConnectionError('list processors', $baseUrl, $startedAt, $e, $logContext);

            return ['processors' => [], 'error' => 'Could not reach FluidPay: '.$e->getMessage().' [key: '.$keyHint.']'];
        }

        $this->logApiCall('list processors', $processorsResponse, $baseUrl, $startedAt, $logContext + [
            'processor_count' => count((array) ($processorsResponse->json('data') ?? [])),
        ]);

        if (! $processorsResponse->successful()) {
            $correlationId = $this->correlationId($processorsResponse);

            return ['processors' => [], 'error' => 'Could not fetch processors for MID "'.$merchantId.'" (HTTP '.$processorsResponse->status().').'
                .$this->correlationSuffix($correlationId).' [key: '.$keyHint.']'];
        }

        $rows = (array) ($processorsResponse->json('data') ?? []);

        $processors = array_map(fn (array $p) => [
            'id'     => (string) ($p['id']     ?? ''),
            'name'   => (string) ($p['name']   ?? ''),
            'status' => (string) ($p['status'] ?? ''),
        ], $rows);

        return ['processors' => $processors, 'error' => null];
    }

    /**
     * Read-only credential check for the "Test credentials" buttons (Gateway Credentials and
     * Multi-MID rows). Never falls back to the env-config key — it tests exactly the key the
     * admin entered or saved. $merchantId null skips the processor check (client-level keys
     * have no MID). Returns one entry per check: ['label', 'status' => pass|warn|fail, 'message'].
     */
    public function testCredentials(array $midCredentials, ?string $merchantId): array
    {
        $apiKey    = trim((string) ($midCredentials['api_key'] ?? ''));
        $publicKey = trim((string) ($midCredentials['public_key'] ?? ''));
        ['base_url' => $baseUrl, 'is_production' => $isProduction] = $this->resolveCredentials($midCredentials);
        $envLabel = $isProduction ? 'production' : 'sandbox';

        $checks = [];
        $check  = function (string $label, string $status, string $message) use (&$checks): void {
            $checks[] = compact('label', 'status', 'message');
        };

        if ($apiKey === '') {
            $check('Private key', 'fail', 'No private key entered or saved for this MID.');
        } elseif (str_starts_with($apiKey, 'pub_')) {
            $check('Private key', 'fail', 'This is a public key (pub_…). The Private Key field needs the api_… key.');
        } elseif (! str_starts_with($apiKey, 'api_')) {
            $check('Private key', 'fail', 'This does not look like a FluidPay private key — they start with "api_".');
        } else {
            $logContext = ['merchant_id' => $merchantId, 'api_key_prefix' => substr($apiKey, 0, 8).'...'];

            try {
                $startedAt = microtime(true);
                $response = Http::withHeaders(['Authorization' => $apiKey])
                    ->timeout(15)
                    ->post("{$baseUrl}/api/transaction/search", ['limit' => 1]);
                $this->logApiCall('test credentials: key check', $response, $baseUrl, $startedAt, $logContext);

                $keyRef = $this->correlationSuffix($this->correlationId($response));

                match (true) {
                    $response->successful()     => $check('Private key', 'pass', "Accepted by FluidPay ({$envLabel})."),
                    $response->status() === 401 => $check('Private key', 'fail', "Rejected by FluidPay {$envLabel} (401) — the key is wrong, revoked, or from the other environment.".$keyRef),
                    $response->status() === 403 => $check('Private key', 'warn', 'Recognised by FluidPay, but not allowed to search transactions (403).'.$keyRef),
                    default                     => $check('Private key', 'fail', 'FluidPay returned HTTP '.$response->status().'.'.$keyRef),
                };

                if ($merchantId === null) {
                    // Client-level keys: no MID to check processors against.
                } elseif ($response->status() === 401) {
                    $check('Processor access', 'warn', 'Skipped — fix the private key first.');
                } elseif ($merchantId === '') {
                    $check('Processor access', 'warn', 'Enter a MID Identifier to check processor access.');
                } else {
                    $startedAt = microtime(true);
                    $processors = Http::withHeaders(['Authorization' => $apiKey])
                        ->timeout(15)
                        ->get("{$baseUrl}/api/merchant/{$merchantId}/processors");
                    $count = count((array) ($processors->json('data') ?? []));
                    $procRef = $this->correlationSuffix($this->correlationId($processors));

                    $this->logApiCall('test credentials: processor access', $processors, $baseUrl, $startedAt, $logContext + [
                        'processor_count' => $count,
                    ]);

                    match (true) {
                        $processors->successful() && $count > 0 => $check('Processor access', 'pass', "{$count} processor(s) found for this MID."),
                        $processors->successful()               => $check('Processor access', 'warn', 'Key can read this MID, but it has no processors set up in FluidPay.'),
                        $processors->status() === 403           => $check('Processor access', 'fail', 'Key is not allowed to read this MID\'s processors (FluidPay: forbidden). The key\'s FluidPay user needs merchant/processor view permission, or the key belongs to a different merchant.'.$procRef),
                        $processors->status() === 404           => $check('Processor access', 'fail', 'FluidPay could not find this MID Identifier — check it is FluidPay\'s merchant ID.'.$procRef),
                        default                                 => $check('Processor access', 'fail', 'FluidPay returned HTTP '.$processors->status().' for this MID.'.$procRef),
                    };
                }
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $this->logConnectionError('test credentials', $baseUrl, $startedAt, $e, $logContext);
                $check('Private key', 'fail', 'Could not reach FluidPay: '.$e->getMessage());
            }
        }

        if ($publicKey === '') {
            $check('Public key', 'fail', 'No public key entered or saved for this MID — the card form cannot load without it.');
        } elseif (str_starts_with($publicKey, 'api_')) {
            $check('Public key', 'fail', 'This is the private key (api_…). Never put it in the public key field — it is sent to the customer\'s browser.');
        } elseif (! str_starts_with($publicKey, 'pub_')) {
            $check('Public key', 'fail', 'This does not look like a FluidPay public key — they start with "pub_".');
        }

        return [
            'checks'       => $checks,
            // A public key is meant for the browser, so it is safe to hand back for the
            // in-page card-form load test. The private key never leaves the server.
            'tokenizer'    => str_starts_with($publicKey, 'pub_') ? [
                'script_url' => (string) (config($isProduction ? 'services.fluidpay.tokenizer_url_production' : 'services.fluidpay.tokenizer_url') ?: $baseUrl.'/tokenizer/tokenizer.js'),
                'base_url'   => $baseUrl,
                'public_key' => $publicKey,
            ] : null,
            'environment'  => $envLabel,
        ];
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

    /**
     * FluidPay tags every API response with an x-correlation-id header. FluidPay support
     * asks for it to trace a failed call on their side, so surface it on errors.
     */
    private function correlationId(\Illuminate\Http\Client\Response $response): ?string
    {
        $id = trim((string) $response->header('x-correlation-id'));

        return $id !== '' ? $id : null;
    }

    /**
     * One log line per FluidPay API call: info on success, warning on an HTTP error (with
     * FluidPay's body). Info, not debug, so it survives a production LOG_LEVEL of info.
     */
    private function logApiCall(string $operation, \Illuminate\Http\Client\Response $response, string $baseUrl, float $startedAt, array $context = []): void
    {
        $entry = [
            'status'         => $response->status(),
            'correlation_id' => $this->correlationId($response),
            'base_url'       => $baseUrl,
            'elapsed_ms'     => (int) ((microtime(true) - $startedAt) * 1000),
        ] + $context;

        if ($response->successful()) {
            \Log::info("FluidPay API {$operation}", $entry);

            return;
        }

        \Log::warning("FluidPay API {$operation} failed", $entry + [
            'body' => $response->json() ?? $response->body(),
        ]);
    }

    private function logConnectionError(string $operation, string $baseUrl, float $startedAt, \Throwable $e, array $context = []): void
    {
        \Log::error("FluidPay API {$operation} connection error", [
            'base_url'   => $baseUrl,
            'elapsed_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'error'      => $e->getMessage(),
        ] + $context);
    }

    private function correlationSuffix(?string $correlationId): string
    {
        return $correlationId !== null ? ' (FluidPay Correlation ID: '.$correlationId.')' : '';
    }
}
