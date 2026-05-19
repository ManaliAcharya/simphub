<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\Client;

class CrmTransactionController extends Controller
{
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
}
