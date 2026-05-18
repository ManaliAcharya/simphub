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
            'status'   => ['nullable', 'string', 'in:CAPTURED,FAILED,PENDING,REFUNDED'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
        ]);

        $transactions = Transaction::query()
            ->whereHas('invoice', fn ($q) => $q
                ->where('pms_client_id', $client->pms_client_id)
                ->where('pms_source', 'custom')
            )
            ->with('invoice:id,invoice_number,amount_cents,currency,description,customer')
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $transactions->map(fn (Transaction $t) => [
                'transaction_id' => $t->id,
                'invoice_id'     => $t->invoice_id,
                'invoice_number' => $t->invoice?->invoice_number,
                'gateway'        => $t->gateway,
                'gateway_txn_id' => $t->gateway_txn_id,
                'status'         => $t->status,
                'amount_cents'   => $t->amount_cents,
                'currency'       => $t->currency,
                'fund_type'      => $t->fund_type,
                'created_at'     => $t->created_at->toIso8601String(),
            ]),
            'meta' => [
                'total'    => $transactions->total(),
                'per_page' => $transactions->perPage(),
                'page'     => $transactions->currentPage(),
                'pages'    => $transactions->lastPage(),
            ],
        ]);
    }
}
