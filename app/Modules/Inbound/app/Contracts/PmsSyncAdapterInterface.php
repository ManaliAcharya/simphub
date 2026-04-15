<?php

namespace Modules\Inbound\Contracts;

use Modules\Inbound\DTOs\StandardInvoice;
use Modules\Inbound\DTOs\WebhookEvent;

interface PmsSyncAdapterInterface
{
    public function supports(WebhookEvent $event): bool;

    public function mapInvoice(WebhookEvent $event): ?StandardInvoice;
}
