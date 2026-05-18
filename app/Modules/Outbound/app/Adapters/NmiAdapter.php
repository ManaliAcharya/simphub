<?php

namespace Modules\Outbound\Adapters;

use Illuminate\Support\Str;
use Modules\Outbound\Contracts\GatewayAdapterInterface;
use Modules\Outbound\DTOs\ChargeRequest;
use Modules\Outbound\DTOs\GatewayResponse;
use Modules\Outbound\DTOs\HostedFieldsConfig;
use Modules\Outbound\DTOs\RefundRequest;

class NmiAdapter implements GatewayAdapterInterface
{
    public function code(): string
    {
        return 'nmi';
    }

    public function charge(ChargeRequest $request): GatewayResponse
    {
        if ($request->token === '') {
            return GatewayResponse::declined('Missing payment token.');
        }

        return GatewayResponse::approved(
            transactionReference: 'nmi_'.Str::lower((string) Str::uuid()),
            gatewayToken: $request->token,
            raw: [
                'gateway' => 'nmi',
                'amount_cents' => $request->amountInCents,
                'idempotency_key' => $request->idempotencyKey,
            ],
        );
    }

    public function refund(RefundRequest $request): GatewayResponse
    {
        return GatewayResponse::approved(
            transactionReference: 'nmi_refund_' . Str::lower((string) Str::uuid()),
            gatewayToken: null,
            raw: [
                'gateway' => 'nmi',
                'type' => 'refund',
                'amount_cents' => $request->amountInCents,
                'original_txn_id' => $request->gatewayTxnId,
            ],
        );
    }

    public function hostedFieldsConfig(string $mid, array $midCredentials = []): HostedFieldsConfig
    {
        $publicKey = (string) ($midCredentials['public_key'] ?? env('NMI_COLLECTJS_PUBLIC_KEY'));

        return new HostedFieldsConfig(
            gateway: 'nmi',
            fields: [
                'script_url' => env('NMI_COLLECTJS_URL', 'https://secure.networkmerchants.com/token/Collect.js'),
                'variant' => 'inline',
                'placeholders' => [
                    'ccnumber' => 'Card number',
                    'ccexp' => 'MM / YY',
                    'cvv' => 'CVV',
                ],
            ],
            metadata: [
                'mid' => $mid,
                'public_key' => $publicKey,
                'mode' => $publicKey !== '' ? 'collectjs' : 'mock',
            ],
        );
    }
}
