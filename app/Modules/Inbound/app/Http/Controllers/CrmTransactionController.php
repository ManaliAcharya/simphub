<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Jobs\DispatchCustomWebhookJob;
use Modules\Inbound\Models\Client;
use Modules\Outbound\Factory\GatewayAdapterFactory;
use Modules\Routing\Models\RoutingRule;

class CrmTransactionController extends Controller
{
    public function __construct(
        private readonly GatewayAdapterFactory $gateways,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->attributes->get('crm_client');

        $request->validate([
            'gateway'    => ['nullable', 'string'],
            'status'     => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date'   => ['nullable', 'date'],
            'per_page'   => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'       => ['nullable', 'integer', 'min:1'],
        ]);

        $allowedGateways = array_map('strtolower', $client->allowed_payment_gateways ?? []);

        if ($request->filled('gateway')) {
            $requested = strtolower($request->input('gateway'));
            if (! in_array($requested, $allowedGateways, true)) {
                return response()->json([
                    'error' => ['code' => 'gateway_not_allowed', 'message' => 'Gateway not enabled for this client.'],
                ], 400);
            }
            $allowedGateways = [$requested];
        }

        $perPage = $request->integer('per_page', 20);
        $page    = $request->integer('page', 1);

        $transactions = Transaction::query()
            ->whereHas('invoice', fn ($q) => $q
                ->where('pms_client_id', $client->pms_client_id)
                ->where('pms_source', 'custom')
            )
            ->with('invoice:id,invoice_number')
            ->when($allowedGateways, fn ($q) => $q->whereIn('gateway', $allowedGateways))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('start_date'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->input('end_date'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $transactions->map(fn (Transaction $t) => [
                'transaction_id'   => $t->id,
                'gateway_txn_id'   => (string) $t->gateway_txn_id,
                'invoice_id'       => $t->invoice_id,
                'invoice_number'   => $t->invoice?->invoice_number,
                'gateway'          => $t->gateway,
                'transaction_type' => $t->transaction_type,
                'status'           => $t->status,
                'amount_cents'     => $t->amount_cents,
                'currency'         => strtoupper((string) $t->currency),
                'created_at'       => $t->created_at->toIso8601String(),
            ]),
            'meta' => [
                'total'    => $transactions->total(),
                'per_page' => $transactions->perPage(),
                'page'     => $transactions->currentPage(),
            ],
        ]);
    }

    public function cancel(Request $request, string $transactionId): JsonResponse
    {
        /** @var Client $client */
        $client = $request->attributes->get('crm_client');

        $txn = Transaction::query()
            ->where('id', $transactionId)
            ->where('transaction_type', 'debit')
            ->whereHas('invoice', fn ($q) => $q
                ->where('pms_client_id', $client->pms_client_id)
                ->where('pms_source', 'custom')
            )
            ->with('invoice')
            ->first();

        if (! $txn) {
            return response()->json([
                'error' => ['code' => 'not_found', 'message' => 'Transaction not found.'],
            ], 404);
        }

        if ($txn->status !== 'CAPTURED') {
            return response()->json([
                'error' => [
                    'code'    => 'validation_error',
                    'message' => "Transaction cannot be voided in its current status ({$txn->status}). Only CAPTURED transactions can be voided.",
                ],
            ], 400);
        }

        $invoice = $txn->invoice;

        if ($invoice->status === 'CANCELLED') {
            return response()->json([
                'error' => ['code' => 'validation_error', 'message' => 'Invoice is already cancelled.'],
            ], 400);
        }

        $midCredentials = [];
        $routingRule = RoutingRule::find($txn->routing_rule_id);
        if ($routingRule?->mid_credentials) {
            try {
                $midCredentials = (array) decrypt($routingRule->mid_credentials);
            } catch (\Throwable) {}
        }

        try {
            $voidResponse = $this->gateways->make($txn->gateway)->void(
                (string) $txn->gateway_txn_id,
                $midCredentials,
            );
        } catch (\Throwable $e) {
            AuditLogger::log('VOID_FAILED', 'transaction', $txn->id, [
                'gateway'        => $txn->gateway,
                'gateway_txn_id' => $txn->gateway_txn_id,
                'error'          => $e->getMessage(),
            ]);

            return response()->json([
                'error' => [
                    'code'    => 'void_failed',
                    'message' => 'Could not reach the gateway to void this transaction. Please try again or use the refund API.',
                ],
            ], 400);
        }

        AuditLogger::log('VOID_ATTEMPTED', 'transaction', $txn->id, [
            'gateway'        => $txn->gateway,
            'gateway_txn_id' => $txn->gateway_txn_id,
            'approved'       => $voidResponse->approved,
            'message'        => $voidResponse->message ?? '',
        ]);

        if (! $voidResponse->approved) {
            return response()->json([
                'error' => [
                    'code'            => 'void_declined',
                    'message'         => 'The transaction has already been settled at the gateway and cannot be voided. Use the refund API instead.',
                    'gateway_message' => $voidResponse->message,
                ],
            ], 400);
        }

        $txn->update(['status' => 'VOIDED']);

        $invoice->update(['status' => 'CANCELLED']);

        PaymentSession::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', ['PENDING', 'AWAITING_PAYMENT', 'PROCESSING'])
            ->update(['status' => 'FAILED']);

        if (! empty($client->webhook_url)) {
            DispatchCustomWebhookJob::dispatch($invoice->id, 'invoice.cancelled');
        }

        return response()->json([
            'transaction_id' => $txn->id,
            'invoice_id'     => $invoice->id,
            'status'         => 'voided',
        ]);
    }
}
