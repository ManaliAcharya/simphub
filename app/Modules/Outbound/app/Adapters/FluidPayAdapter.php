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

    public function hostedFieldsConfig(string $mid): HostedFieldsConfig
    {
        return new HostedFieldsConfig(
            gateway: 'fluidpay',
            fields: [],
            metadata: [
                'mid' => $mid,
                'mode' => 'mock',
            ],
        );
    }
}
