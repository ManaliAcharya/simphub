<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Services\CrmRefundService;
use RuntimeException;

class CrmRefundController extends Controller
{
    public function __construct(
        private readonly CrmRefundService $refunds,
    ) {}

    public function store(Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->attributes->get('crm_client');

        $validated = $request->validate([
            'transaction_id' => ['required', 'uuid'],
            'amount_cents'   => ['nullable', 'integer', 'min:1'],
            'reason'         => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $refund = $this->refunds->refund(
                $client,
                $validated['transaction_id'],
                isset($validated['amount_cents']) ? (int) $validated['amount_cents'] : null,
                $validated['reason'] ?? null,
            );
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'not_found') {
                return response()->json([
                    'error' => ['code' => 'not_found', 'message' => 'Transaction not found.'],
                ], 404);
            }

            return response()->json([
                'error' => ['code' => 'refund_failed', 'message' => $e->getMessage()],
            ], 400);
        }

        return response()->json([
            'refund_id'               => $refund->id,
            'original_transaction_id' => $refund->parent_transaction_id,
            'invoice_id'              => $refund->invoice_id,
            'invoice_number'          => $refund->invoice?->invoice_number,
            'status'                  => $refund->status,
            'amount_cents'            => $refund->amount_cents,
            'currency'                => $refund->currency,
            'gateway'                 => $refund->gateway,
            'gateway_txn_id'          => $refund->gateway_txn_id,
            'created_at'              => $refund->created_at->toIso8601String(),
        ], 201);
    }
}
