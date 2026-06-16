<?php

namespace Modules\BookSync\Services;

use Modules\BookSync\Contracts\AccountingConnectorInterface;
use RuntimeException;

class AccountingConnectorRegistry
{
    /** @var AccountingConnectorInterface[] */
    private array $map = [];

    /** @param iterable<AccountingConnectorInterface> $connectors */
    public function __construct(iterable $connectors)
    {
        foreach ($connectors as $connector) {
            $this->map[$connector->key()] = $connector;
        }
    }

    public function for(string $key): AccountingConnectorInterface
    {
        return $this->map[$key]
            ?? throw new RuntimeException("Unknown accounting connector [{$key}].");
    }

    /** @return AccountingConnectorInterface[] */
    public function all(): array
    {
        return array_values($this->map);
    }

    public function keys(): array
    {
        return array_keys($this->map);
    }
}
