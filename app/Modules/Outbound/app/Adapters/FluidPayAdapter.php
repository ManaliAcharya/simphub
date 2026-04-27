<?php

namespace Modules\Outbound\Adapters;

use Illuminate\Support\Str;
use Modules\Outbound\Contracts\GatewayAdapterInterface;
use Modules\Outbound\DTOs\ChargeRequest;
use Modules\Outbound\DTOs\GatewayResponse;
use Modules\Outbound\DTOs\HostedFieldsConfig;

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

        return GatewayResponse::approved('fluidpay_'.Str::lower((string) Str::uuid()), $request->token, [
            'gateway' => 'fluidpay',
        ]);
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
