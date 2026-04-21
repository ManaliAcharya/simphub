<?php

namespace Modules\Outbound\Contracts;

use Modules\Outbound\DTOs\ChargeRequest;
use Modules\Outbound\DTOs\GatewayResponse;
use Modules\Outbound\DTOs\HostedFieldsConfig;

interface GatewayAdapterInterface
{
    public function code(): string;

    public function charge(ChargeRequest $request): GatewayResponse;

    public function hostedFieldsConfig(string $mid): HostedFieldsConfig;
}
