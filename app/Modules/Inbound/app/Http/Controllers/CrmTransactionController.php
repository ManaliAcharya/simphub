<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Billing\Models\Transaction;
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

        $perPage = $request->integer('per_page', 20);
        $page    = $request->integer('page', 1);
        $offset  = ($page - 1) * $perPage;

        $allowedGateways = $client->allowed_payment_gateways ?? [];

        if ($request->filled('gateway')) {
            $requested = strtolower($request->input('gateway'));
            if (! in_array($requested, array_map('strtolower', $allowedGateways), true)) {
                return response()->json([
                    'error' => ['code' => 'gateway_not_allowed', 'message' => 'Gateway not enabled for this client.'],
                ], 400);
            }
            $allowedGateways = [$requested];
        }

        $allData    = [];
        $totalCount = 0;

        foreach ($allowedGateways as $gateway) {
            $gateway = strtolower((string) $gateway);

            $midCredentials = [];
            $rule = RoutingRule::query()
                ->where('gateway', $gateway)
                ->where('is_active', true)
                ->orderBy('priority')
                ->first();

            if ($rule?->mid_credentials) {
                try {
                    $midCredentials = (array) decrypt($rule->mid_credentials);
                } catch (\Throwable) {}
            }

            $gatewayRows = [];
            $gatewayTotal = 0;

            try {
                $result = $this->gateways->make($gateway)->listTransactions([
                    'limit'      => $perPage,
                    'offset'     => $offset,
                    'status'     => $request->input('status'),
                    'start_date' => $request->input('start_date'),
                    'end_date'   => $request->input('end_date'),
                ], $midCredentials);

                $gatewayRows  = $result['data'];
                $gatewayTotal = $result['total_count'];
            } catch (\Throwable) {}

            if (! empty($gatewayRows)) {
                // Gateway returned live data — normalize to unified schema
                foreach ($gatewayRows as $row) {
                    $allData[] = $this->normalizeGatewayRow($row, $gateway);
                }
                $totalCount += $gatewayTotal;
            } else {
                // Gateway has no list API (Paya) — fall back to our DB records
                $query = Transaction::query()
                    ->whereHas('invoice', fn ($q) => $q
                        ->where('pms_client_id', $client->pms_client_id)
                        ->where('pms_source', 'custom')
                    )
                    ->where('gateway', $gateway)
                    ->with('invoice:id,invoice_number')
                    ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
                    ->when($request->input('start_date'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                    ->when($request->input('end_date'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
                    ->latest();

                $paginated = $query->paginate($perPage, ['*'], 'page', $page);

                foreach ($paginated->items() as $txn) {
                    $allData[] = $this->normalizeDbRow($txn);
                }
                $totalCount += $paginated->total();
            }
        }

        return response()->json([
            'data' => $allData,
            'meta' => [
                'total'    => $totalCount,
                'per_page' => $perPage,
                'page'     => $page,
            ],
        ]);
    }

    /** Normalize a live gateway API row (FluidPay) to the unified schema. */
    private function normalizeGatewayRow(array $row, string $gateway): array
    {
        $type = strtolower((string) ($row['type'] ?? ''));

        return [
            'transaction_id'   => null,
            'gateway_txn_id'   => (string) ($row['id'] ?? $row['gateway_txn_id'] ?? ''),
            'invoice_id'       => null,
            'invoice_number'   => null,
            'gateway'          => $gateway,
            'transaction_type' => in_array($type, ['sale', 'debit'], true) ? 'debit' : 'credit',
            'status'           => strtoupper((string) ($row['status'] ?? '')),
            'amount_cents'     => (int) ($row['amount'] ?? $row['amount_cents'] ?? 0),
            'currency'         => strtoupper((string) ($row['currency'] ?? 'USD')),
            'created_at'       => (string) ($row['created_at'] ?? ''),
        ];
    }

    /** Normalize a DB Transaction record to the unified schema. */
    private function normalizeDbRow(Transaction $txn): array
    {
        return [
            'transaction_id'   => $txn->id,
            'gateway_txn_id'   => (string) $txn->gateway_txn_id,
            'invoice_id'       => $txn->invoice_id,
            'invoice_number'   => $txn->invoice?->invoice_number,
            'gateway'          => $txn->gateway,
            'transaction_type' => $txn->transaction_type,
            'status'           => $txn->status,
            'amount_cents'     => $txn->amount_cents,
            'currency'         => strtoupper((string) $txn->currency),
            'created_at'       => $txn->created_at->toIso8601String(),
        ];
    }
}
