<?php

namespace Modules\Outbound\Contracts;

use Modules\Outbound\DTOs\ChargeRequest;
use Modules\Outbound\DTOs\GatewayResponse;
use Modules\Outbound\DTOs\HostedFieldsConfig;
use Modules\Outbound\DTOs\RefundRequest;

interface GatewayAdapterInterface
{
    public function code(): string;

    public function charge(ChargeRequest $request): GatewayResponse;

    public function refund(RefundRequest $request): GatewayResponse;

    public function void(string $gatewayTxnId, array $midCredentials = []): GatewayResponse;

    /** @return array{ data: list<array<string,mixed>>, total_count: int } */
    public function listTransactions(array $filters, array $midCredentials = []): array;

    public function hostedFieldsConfig(string $mid, array $midCredentials = []): HostedFieldsConfig;
}
