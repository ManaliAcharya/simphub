<?php

namespace Modules\Inbound\Contracts;

use Modules\Inbound\DTOs\StandardInvoice;
use Modules\Inbound\DTOs\WebhookEvent;

interface PmsSyncAdapterInterface
{
    public function supports(WebhookEvent $event): bool;

    public function mapInvoice(WebhookEvent $event): ?StandardInvoice;
    public function verifyWebhookSignature(Request $request): bool;
    public function parseWebhookEvent(Request $request): WebhookEvent;
    public function fetchInvoice(string $externalId): StandardInvoice;
    public function markInvoicePaid(string $externalId, PaymentReference $ref): void;
    public function getAdapterName(): string; // 'clio' | 'lawcus'
}
