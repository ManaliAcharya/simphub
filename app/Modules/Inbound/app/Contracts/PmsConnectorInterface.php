<?php

namespace Modules\Inbound\Contracts;

use Modules\Inbound\DTOs\PmsCallbackResult;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\PmsConnection;

interface PmsConnectorInterface
{
    public function key(): string;

    public function label(): string;

    public function authorizationUrl(string $pmsClientId): string;

    public function completeAuthorization(string $code, string $pmsClientId): PmsCallbackResult;

    public function webhookMode(): string;

    public function connection(?string $pmsClientId): ?PmsConnection;

    public function integrationData(?Client $client, ?PmsConnection $connection): array;
}
