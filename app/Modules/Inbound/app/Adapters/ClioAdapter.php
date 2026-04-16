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

    public function verifyWebhookSignature(Request $request): bool
    {
        $received = $request->header('X-Clio-Signature');
        $expected = hash_hmac('sha256',
            $request->getContent(),
            config('pms.clio.webhook_secret')
        );
        return hash_equals($expected, $received); // constant-time comparison
    }
    
    public function mapInvoice(WebhookEvent $event): ?StandardInvoice
    {
        return null;
    }
}
