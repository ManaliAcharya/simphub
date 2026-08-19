<?php

namespace Modules\Boarding\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Audit\Services\AuditLogger;
use Modules\Boarding\Models\BoardingClient;
use Modules\Boarding\Models\BoardingMerchant;

class BoardingLinkController extends Controller
{
    /** POST /api/v1/boarding/boarding-links */
    public function store(Request $request): JsonResponse
    {
        /** @var BoardingClient $client */
        $client = $request->attributes->get('boarding_client');

        $data = $request->validate([
            'processor'             => ['required', 'string', 'in:square'],
            'scope'                 => ['required', 'string', 'in:merchant'],
            'tier'                  => ['required', 'string', 'in:rack_rate,flat_rate'],
            'agent_ref'             => ['required', 'string', 'max:100'],
            'merchant_ref'          => ['required', 'string', 'max:100'],
            'merchant_info'         => ['required', 'array'],
            'merchant_info.name'    => ['required', 'string', 'max:255'],
            'merchant_info.zip'     => ['nullable', 'string', 'max:20'],
            'created_by'            => ['nullable', 'string', 'max:100'],
        ]);

        if (! $client->masterLinkFor($data['processor'], $data['tier'])) {
            return response()->json([
                'error' => "No master {$data['processor']} link configured for tier [{$data['tier']}]. Configure it on the client portal before requesting boarding links.",
            ], 422);
        }

        return DB::transaction(function () use ($client, $data) {
            $merchant = BoardingMerchant::where('client_id', $client->id)
                ->where('merchant_ref', $data['merchant_ref'])
                ->lockForUpdate()
                ->first();

            if (! $merchant) {
                $merchant = BoardingMerchant::create([
                    'client_id'     => $client->id,
                    'processor'     => $data['processor'],
                    'scope'         => $data['scope'],
                    'tier'          => $data['tier'],
                    'agent_ref'     => $data['agent_ref'],
                    'merchant_ref'  => $data['merchant_ref'],
                    'merchant_info' => $data['merchant_info'],
                    'created_by'    => $data['created_by'] ?? null,
                    'token'         => Str::random(48),
                    'status'        => 'link_generated',
                ]);

                return $this->formatResponse($merchant, 201);
            }

            if ($merchant->status === 'revoked') {
                AuditLogger::log('boarding_link.reissued', 'BoardingMerchant', $merchant->id, [
                    'previous_tier'  => $merchant->tier,
                    'previous_token' => $merchant->token,
                    'new_tier'       => $data['tier'],
                ]);

                $merchant->forceFill([
                    'tier'          => $data['tier'],
                    'agent_ref'     => $data['agent_ref'],
                    'merchant_info' => $data['merchant_info'],
                    'created_by'    => $data['created_by'] ?? null,
                    'token'         => Str::random(48),
                    'status'        => 'link_generated',
                    'revoked_at'    => null,
                ])->save();

                return $this->formatResponse($merchant, 200);
            }

            if ($merchant->tier === $data['tier']) {
                return $this->formatResponse($merchant, 200);
            }

            if ($merchant->status === 'clicked') {
                return response()->json([
                    'error' => 'Boarding link already clicked; tier is locked and cannot be changed.',
                ], 409);
            }

            AuditLogger::log('boarding_link.superseded', 'BoardingMerchant', $merchant->id, [
                'previous_tier'  => $merchant->tier,
                'previous_token' => $merchant->token,
                'new_tier'       => $data['tier'],
            ]);

            $merchant->forceFill([
                'tier'          => $data['tier'],
                'agent_ref'     => $data['agent_ref'],
                'merchant_info' => $data['merchant_info'],
                'created_by'    => $data['created_by'] ?? null,
                'token'         => Str::random(48),
                'status'        => 'link_generated',
            ])->save();

            return $this->formatResponse($merchant, 200);
        });
    }

    /** GET /api/v1/boarding/merchants/{merchant_ref} */
    public function show(Request $request, string $merchant_ref): JsonResponse
    {
        /** @var BoardingClient $client */
        $client = $request->attributes->get('boarding_client');

        $merchant = BoardingMerchant::where('client_id', $client->id)
            ->where('merchant_ref', $merchant_ref)
            ->first();

        if (! $merchant) {
            return response()->json([
                'error' => "No merchant found for merchant_ref [{$merchant_ref}].",
            ], 404);
        }

        return response()->json([
            'merchant_ref'  => $merchant->merchant_ref,
            'merchant_info' => $merchant->merchant_info,
            'agent_ref'     => $merchant->agent_ref,
            'processor'     => $merchant->processor,
            'scope'         => $merchant->scope,
            'tier'          => $merchant->tier,
            'status'        => $merchant->status,
            'boarding_link' => $merchant->boardingLink(),
            'created_by'    => $merchant->created_by,
            'created_at'    => $merchant->created_at?->toIso8601String(),
            'clicked_at'    => $merchant->clicked_at?->toIso8601String(),
            'revoked_at'    => $merchant->revoked_at?->toIso8601String(),
        ]);
    }

    /** POST /api/v1/boarding/boarding-links/revoke?agent_ref=... */
    public function revoke(Request $request): JsonResponse
    {
        /** @var BoardingClient $client */
        $client = $request->attributes->get('boarding_client');

        $data = $request->validate([
            'agent_ref' => ['required', 'string', 'max:100'],
        ]);

        $merchants = BoardingMerchant::where('client_id', $client->id)
            ->where('agent_ref', $data['agent_ref'])
            ->where('status', '!=', 'revoked')
            ->get();

        foreach ($merchants as $merchant) {
            $previousStatus = $merchant->status;

            $merchant->forceFill([
                'status'     => 'revoked',
                'revoked_at' => now(),
            ])->save();

            AuditLogger::log('boarding_link.revoked', 'BoardingMerchant', $merchant->id, [
                'agent_ref'    => $merchant->agent_ref,
                'merchant_ref' => $merchant->merchant_ref,
                'was_status'   => $previousStatus,
            ]);
        }

        return response()->json([
            'agent_ref'      => $data['agent_ref'],
            'revoked_count'  => $merchants->count(),
            'merchant_refs'  => $merchants->pluck('merchant_ref')->values(),
        ]);
    }

    private function formatResponse(BoardingMerchant $merchant, int $status): JsonResponse
    {
        return response()->json([
            'merchant_ref'  => $merchant->merchant_ref,
            'processor'     => $merchant->processor,
            'tier'          => $merchant->tier,
            'status'        => $merchant->status,
            'boarding_link' => $merchant->boardingLink(),
            'created_at'    => $merchant->created_at?->toIso8601String(),
        ], $status);
    }
}
