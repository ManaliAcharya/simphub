<?php

namespace Modules\Outbound\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Models\Client;
use Modules\Outbound\Factory\TerminalAdapterFactory;
use Modules\Routing\Models\TerminalConfiguration;

class TerminalPaymentController extends Controller
{
    public function __construct(private readonly TerminalAdapterFactory $factory) {}

    public function charge(Request $request, string $merchantId): JsonResponse
    {
        $client = Client::query()
            ->where('pms_client_id', $merchantId)
            ->first();

        if (! $client) {
            return response()->json(['error' => 'Merchant not found.'], 404);
        }

        $terminals = $client->allowed_terminals ?? [];

        if (empty($terminals)) {
            return response()->json(['error' => 'No terminal configured for this merchant.'], 422);
        }

        $terminalCode = strtolower($terminals[0]);

        $config = TerminalConfiguration::query()
            ->where('merchant_id', $merchantId)
            ->where('terminal', $terminalCode)
            ->where('is_active', true)
            ->first();

        if (! $config) {
            return response()->json(['error' => 'Terminal configuration not found for this merchant.'], 422);
        }

        try {
            $adapter = $this->factory->make($terminalCode);
            $result  = $adapter->process($merchantId, $request->all(), $config->getCredentials());

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }
}
