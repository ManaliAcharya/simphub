<?php

namespace Modules\Outbound\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Models\Client;
use Modules\Outbound\Services\PayaTokenizerService;
use Modules\Routing\Models\RoutingRule;
use RuntimeException;

class PayaTokenizeController extends Controller
{
    public function __construct(private readonly PayaTokenizerService $tokenizer) {}

    public function tokenize(Request $request, string $merchantId): JsonResponse
    {
        $client = Client::query()
            ->where('pms_client_id', $merchantId)
            ->first();

        if (! $client) {
            return response()->json(['error' => 'Merchant not found.'], 404);
        }

        $validated = $request->validate([
            'routing_number' => ['required', 'string', 'regex:/^\d{9}$/'],
            'account_number' => ['required', 'string', 'regex:/^\d{4,17}$/'],
            'account_type'   => ['nullable', 'string', 'in:checking,savings'],
            'first_name'     => ['nullable', 'string', 'max:100'],
            'last_name'      => ['nullable', 'string', 'max:100'],
            'address1'       => ['nullable', 'string', 'max:255'],
            'city'           => ['nullable', 'string', 'max:100'],
            'state'          => ['nullable', 'string', 'size:2'],
            'zip'            => ['nullable', 'string', 'max:10'],
            'phone_number'   => ['nullable', 'string', 'max:20'],
        ]);

        $rule = RoutingRule::query()
            ->where('gateway', 'paya')
            ->where('is_active', true)
            ->orderBy('priority')
            ->first();

        $credentials = [];
        if ($rule && $rule->mid_credentials) {
            try {
                $credentials = (array) decrypt($rule->mid_credentials);
            } catch (\Throwable) {
                $credentials = [];
            }
        }

        try {
            $token = $this->tokenizer->tokenizeViaPaya($validated, $credentials);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'token'        => $token,
            'account_type' => strtolower($validated['account_type'] ?? 'checking'),
            'last4'        => substr($validated['account_number'], -4),
        ]);
    }
}
