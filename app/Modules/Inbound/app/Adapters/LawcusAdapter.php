<?php

namespace Modules\Inbound\Adapters;

use Modules\Inbound\Contracts\PmsSyncAdapterInterface;
use Modules\Inbound\DTOs\StandardInvoice;
use Modules\Inbound\DTOs\WebhookEvent;

class LawcusAdapter implements PmsSyncAdapterInterface
{
    public function supports(WebhookEvent $event): bool
    {
        return $event->source === 'lawcus';
    }

    public function mapInvoice(WebhookEvent $event): ?StandardInvoice
    {
        return null;
    }
}
