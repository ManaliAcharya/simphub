<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Modules\Inbound\Models\PmsConnection;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ZohoWebhookService
{
    public function __construct(
        private readonly InternalInboundApiCaller $inboundApi,
    ) {}

    public function handleIncoming(Request $request): Response
    {
        $pmsClientId = (string) $request->query('pms_client_id', '');
        $connection = $this->findConnection($pmsClientId);

        if (! $connection) {
            return response()->json(['message' => 'Unknown PMS client identifier for Zoho webhook.'], 404);
        }

        $payloadData = $request->json()->all();
        $invoiceId = (string) (
            Arr::get($payloadData, 'bill_id')
            ?? Arr::get($payloadData, 'data.bill_id')
            ?? Arr::get($payloadData, 'data.bill.bill_id')
            ?? Arr::get($payloadData, 'bill.bill_id')
            ?? Arr::get($payloadData, 'entity_id')
            ?? Arr::get($payloadData, 'data.entity_id')
            ?? Arr::get($payloadData, 'resource_id')
            ?? Arr::get($payloadData, 'data.resource_id')
            ?? ''
        );

        if ($invoiceId === '') {
            return response()->json(['message' => 'Webhook payload does not contain a bill id.'], 422);
        }

        $organizationId = (string) (
            Arr::get($payloadData, 'organization_id')
            ?? Arr::get($payloadData, 'organization.organization_id')
            ?? Arr::get($payloadData, 'data.organization_id')
            ?? data_get($connection->meta, 'default_organization_id')
            ?? ''
        );

        $this->inboundApi->callInvoiceIngestion('zoho', array_merge($payloadData, [
            'invoice_id' => $invoiceId,
            'bill_id' => $invoiceId,
            'pms_client_id' => $connection->pms_client_id,
            'organization_id' => $organizationId,
            'event_name' => (string) (
                Arr::get($payloadData, 'event')
                ?? Arr::get($payloadData, 'event_type')
                ?? Arr::get($payloadData, 'data.event')
                ?? 'bill.created'
            ),
            'headers' => $request->headers->all(),
        ]));

        return response()->json(['accepted' => true], 202);
    }

    private function findConnection(string $pmsClientId): ?PmsConnection
    {
        if ($pmsClientId === '') {
            return null;
        }

        return PmsConnection::query()
            ->where('provider', 'zoho')
            ->where('pms_client_id', $pmsClientId)
            ->first();
    }
}
