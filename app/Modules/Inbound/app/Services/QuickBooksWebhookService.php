<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Modules\Inbound\Jobs\ProcessQBInvoiceLinkJob;
use Modules\Inbound\Models\QuickBooksConnection;
use Symfony\Component\HttpFoundation\Response;

class QuickBooksWebhookService
{
    public function __construct(
        private readonly InternalInboundApiCaller $inboundApi,
    ) {}

    public function handleIncoming(Request $request): Response
    {
        // Verify the intuit-signature header using the webhook verifier token.
        $verifierToken = (string) config('services.quickbooks.webhook_verifier_token', '');

        if ($verifierToken !== '') {
            $signature = (string) $request->header('intuit-signature', '');
            $payload   = $request->getContent();
            $expected  = base64_encode(hash_hmac('sha256', $payload, $verifierToken, true));

            if (! hash_equals($expected, $signature)) {
                return response()->json(['message' => 'Invalid QuickBooks webhook signature.'], 401);
            }
        }

        $notifications = (array) $request->json('eventNotifications', []);

        foreach ($notifications as $notification) {
            $realmId  = (string) ($notification['realmId'] ?? '');
            $entities = (array) Arr::get($notification, 'dataChangeEvent.entities', []);

            if ($realmId === '' || empty($entities)) {
                continue;
            }

            $connection = $this->findConnectionByRealmId($realmId);

            if (! $connection) {
                continue;
            }

            foreach ($entities as $entity) {
                $name      = strtolower((string) ($entity['name'] ?? ''));
                $operation = strtolower((string) ($entity['operation'] ?? ''));
                $id        = (string) ($entity['id'] ?? '');

                if ($id === '') {
                    continue;
                }

                // Dispatch payment link lifecycle job for Invoice (all ops) and Payment (create)
                if (
                    $name === 'invoice'
                    || ($name === 'payment' && $operation === 'create')
                ) {
                    ProcessQBInvoiceLinkJob::dispatch(
                        $name,
                        $operation,
                        $id,
                        $realmId,
                        $connection->pms_client_id,
                    );
                }

                // Invoice ingestion — existing flow, skip delete/void
                if ($name === 'invoice' && ! in_array($operation, ['delete', 'void'], true)) {
                    try {
                        $this->inboundApi->callInvoiceIngestion('quickbooks', [
                            'invoice_id'     => $id,
                            'pms_client_id'  => $connection->pms_client_id,
                            'realm_id'       => $realmId,
                            'operation'      => $operation,
                            'event_name'     => "invoice.{$operation}",
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('QuickBooks webhook: invoice ingestion failed', [
                            'invoice_id'    => $id,
                            'realm_id'      => $realmId,
                            'pms_client_id' => $connection->pms_client_id,
                            'error'         => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return response()->json(['accepted' => true], 202);
    }

    private function findConnectionByRealmId(string $realmId): ?QuickBooksConnection
    {
        return QuickBooksConnection::query()
            ->where('provider', 'quickbooks')
            ->get()
            ->first(fn (QuickBooksConnection $c) => $c->realmId() === $realmId);
    }
}
