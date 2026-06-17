<?php

namespace Modules\BookSync\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\BookSync\Models\BookSyncClient;
use Modules\BookSync\Models\BookSyncMerchant;

class MerchantApiController extends Controller
{
    /** GET /booksync/api/v1/merchants */
    public function index(Request $request): JsonResponse
    {
        /** @var BookSyncClient $client */
        $client = $request->attributes->get('booksync_client');
        $query  = BookSyncMerchant::where('client_id', $client->id)->latest();
        $status = $request->query('status');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $merchants = $query->get();

        return response()->json([
            'data'  => $merchants->map(fn (BookSyncMerchant $m) => $this->formatMerchant($m))->values()->all(),
            'total' => $merchants->count(),
        ]);
    }

    /** POST /booksync/api/v1/merchants */
    public function store(Request $request): JsonResponse
    {
        /** @var BookSyncClient $client */
        $client = $request->attributes->get('booksync_client');

        $data = $request->validate([
            'merchant_name'        => ['required', 'string', 'max:255'],
            'merchant_email'       => ['nullable', 'email', 'max:255'],
            'external_merchant_id' => ['nullable', 'string', 'max:100'],
            'callback_url'         => ['nullable', 'url', 'max:2048'],
        ]);

        $signingSecret = 'bss_' . Str::random(52);

        $merchant = BookSyncMerchant::create([
            'client_id'            => $client->id,
            'name'                 => $data['merchant_name'],
            'merchant_email'       => $data['merchant_email'] ?? null,
            'external_merchant_id' => $data['external_merchant_id'] ?? null,
            'merchant_id'          => 'm_' . Str::uuid()->toString(),
            'setup_token'          => Str::random(48),
            'signing_secret'       => $signingSecret,
            'callback_url'         => $data['callback_url'] ?? null,
            'status'               => 'pending_qb_connect',
        ]);

        // signing_secret returned ONLY in this 201 response — never again
        return response()->json(
            array_merge($this->formatMerchant($merchant), [
                'merchant_token' => $merchant->setup_token,
                'signing_secret' => $signingSecret,
            ]),
            201
        );
    }

    /** GET /booksync/api/v1/merchants/{merchant_id} */
    public function show(Request $request, string $merchantId): JsonResponse
    {
        /** @var BookSyncClient $client */
        $client = $request->attributes->get('booksync_client');

        $merchant = BookSyncMerchant::where('merchant_id', $merchantId)
            ->where('client_id', $client->id)
            ->firstOrFail();

        return response()->json($this->formatMerchant($merchant));
    }

    /** POST /booksync/api/v1/merchants/{merchant_id}/rotate-secret */
    public function rotateSecret(Request $request, string $merchantId): JsonResponse
    {
        /** @var BookSyncClient $client */
        $client = $request->attributes->get('booksync_client');

        $merchant = BookSyncMerchant::where('merchant_id', $merchantId)
            ->where('client_id', $client->id)
            ->firstOrFail();

        $newSecret = 'bss_' . Str::random(52);
        $expiresAt = now()->addMinutes(30);

        $merchant->forceFill([
            'previous_signing_secret'    => $merchant->signing_secret,
            'previous_secret_expires_at' => $expiresAt,
            'signing_secret'             => $newSecret,
        ])->save();

        // New secret shown only once in this response
        return response()->json([
            'merchant_id'                 => $merchant->merchant_id,
            'signing_secret'              => $newSecret,
            'previous_secret_valid_until' => $expiresAt->toIso8601String(),
        ]);
    }

    private function formatMerchant(BookSyncMerchant $merchant): array
    {
        return [
            'merchant_id'          => $merchant->merchant_id,
            'merchant_name'        => $merchant->name,
            'external_merchant_id' => $merchant->external_merchant_id,
            'status'               => $merchant->status,
            'qb_connected'         => $merchant->isQbConnected(),
            'qb_company_name'      => $merchant->qb_company_name,
            'deposit_account'      => $merchant->deposit_account_id ? [
                'id'   => $merchant->deposit_account_id,
                'name' => $merchant->deposit_account_name,
            ] : null,
            'default_item'         => $merchant->default_item_id ? [
                'id'   => $merchant->default_item_id,
                'name' => $merchant->default_item_name,
            ] : null,
            'default_customer'     => $merchant->default_customer_id ? [
                'id'   => $merchant->default_customer_id,
                'name' => $merchant->default_customer_name,
            ] : null,
            'surcharge_enabled'    => (bool) $merchant->surcharge_enabled,
            'surcharge_item'       => $merchant->surcharge_item_id ? [
                'id'   => $merchant->surcharge_item_id,
                'name' => $merchant->surcharge_item_name,
            ] : null,
            'setup_link'           => $merchant->setupLink(),
            'posting_url'          => $merchant->postingUrl(),
            'created_at'           => $merchant->created_at?->toIso8601String(),
            'qb_connected_at'      => $merchant->qb_connected_at?->toIso8601String(),
        ];
    }
}
