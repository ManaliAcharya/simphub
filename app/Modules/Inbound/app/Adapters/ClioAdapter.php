<?php

namespace Modules\Inbound\Adapters;

use Modules\Inbound\Contracts\PmsSyncAdapterInterface;
use Modules\Inbound\DTOs\StandardInvoice;
use Modules\Inbound\DTOs\WebhookEvent;

class ClioAdapter implements PmsSyncAdapterInterface
{
    public function supports(WebhookEvent $event): bool
    {
        return $event->source === 'clio';
    }

    public function mapInvoice(WebhookEvent $event): ?StandardInvoice
    {
        return null;
    }
}
