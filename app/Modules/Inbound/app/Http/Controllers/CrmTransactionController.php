<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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

        $perPage    = $request->integer('per_page', 20);
        $page       = $request->integer('page', 1);
        $offset     = ($page - 1) * $perPage;

        $filters = array_filter([
            'limit'      => $perPage,
            'offset'     => $offset,
            'status'     => $request->input('status'),
            'start_date' => $request->input('start_date'),
            'end_date'   => $request->input('end_date'),
        ], fn ($v) => $v !== null && $v !== '');

        // Determine which gateways to query
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

            // Resolve MID credentials from first active routing rule for this gateway
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

            try {
                $result = $this->gateways->make($gateway)->listTransactions($filters, $midCredentials);
            } catch (\Throwable) {
                continue;
            }

            foreach ($result['data'] as $txn) {
                $txn['gateway'] = $gateway;
                $allData[] = $txn;
            }

            $totalCount += $result['total_count'];
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
}
