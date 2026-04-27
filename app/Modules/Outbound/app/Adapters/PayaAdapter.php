<?php

namespace Modules\Outbound\Adapters;

use Illuminate\Support\Str;
use Modules\Outbound\Contracts\GatewayAdapterInterface;
use Modules\Outbound\DTOs\ChargeRequest;
use Modules\Outbound\DTOs\GatewayResponse;
use Modules\Outbound\DTOs\HostedFieldsConfig;

class PayaAdapter implements GatewayAdapterInterface
{
    public function code(): string
    {
        return 'paya';
    }

    public function charge(ChargeRequest $request): GatewayResponse
    {
        if ($request->token === '') {
            return GatewayResponse::declined('Missing payment token.');
        }

        return GatewayResponse::approved('paya_'.Str::lower((string) Str::uuid()), $request->token, [
            'gateway' => 'paya',
        ]);
    }

    public function hostedFieldsConfig(string $mid, array $midCredentials = []): HostedFieldsConfig
    {
        return new HostedFieldsConfig(
            gateway: 'paya',
            fields: [],
            metadata: [
                'mid' => $mid,
                'mode' => 'mock',
            ],
        );
    }
}
