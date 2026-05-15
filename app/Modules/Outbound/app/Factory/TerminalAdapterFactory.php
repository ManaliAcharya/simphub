<?php

namespace Modules\Outbound\Factory;

use InvalidArgumentException;
use Modules\Outbound\Adapters\DejaVooAdapter;
use Modules\Outbound\Adapters\ValorAdapter;
use Modules\Outbound\Contracts\TerminalAdapterInterface;

class TerminalAdapterFactory
{
    public function make(string $terminal): TerminalAdapterInterface
    {
        return match (strtolower($terminal)) {
            'valor'   => app(ValorAdapter::class),
            'dejavoo' => app(DejaVooAdapter::class),
            default   => throw new InvalidArgumentException("Unsupported terminal [{$terminal}]."),
        };
    }
}
