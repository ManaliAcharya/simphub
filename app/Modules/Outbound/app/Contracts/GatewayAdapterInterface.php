<?php

namespace Modules\Outbound\Contracts;

use Modules\Outbound\DTOs\ChargeRequest;
use Modules\Outbound\DTOs\GatewayResponse;

interface GatewayAdapterInterface
{
    public function code(): string;

    public function charge(ChargeRequest $request): GatewayResponse;
    public function getHostedFieldsConfig(string $mid): HostedFieldsConfig;
    public function supportsMethod(PaymentMethod $method): bool;
    public function getAdapterName(): string; // 'nmi' | 'fluidpay' | 'paya'
}
