<?php

namespace Modules\Inbound\Services;

use Modules\Inbound\Contracts\PmsConnectorInterface;
use RuntimeException;

class PmsConnectorRegistry
{
    /**
     * @param  iterable<PmsConnectorInterface>  $connectors
     */
    public function __construct(
        private readonly iterable $connectors,
    ) {}

    public function for(string $provider): PmsConnectorInterface
    {
        foreach ($this->connectors as $connector) {
            if ($connector->key() === $provider) {
                return $connector;
            }
        }

        throw new RuntimeException("Unsupported PMS provider [{$provider}].");
    }

    public function supportedProviders(): array
    {
        $providers = [];

        foreach ($this->connectors as $connector) {
            $providers[] = $connector->key();
        }

        return $providers;
    }
}
