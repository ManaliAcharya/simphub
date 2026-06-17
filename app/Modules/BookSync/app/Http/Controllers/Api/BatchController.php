<?php

namespace Modules\BookSync\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\BookSync\Http\Requests\PostBatchRequest;
use Modules\BookSync\Models\BookSyncBatch;
use Modules\BookSync\Models\BookSyncClient;
use Modules\BookSync\Models\BookSyncMerchant;
use Modules\BookSync\Services\BookSyncBatchService;

class BatchController extends Controller
{
    // Reject requests with a timestamp older than 5 minutes (replay protection)
    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public function __construct(
        private readonly BookSyncBatchService $batches,
    ) {}

    /** POST /booksync/post/{merchant_token} */
    public function post(PostBatchRequest $request, string $merchantToken): JsonResponse
    {
        /** @var BookSyncClient $client */
        $client = $request->attributes->get('booksync_client');

        $merchant = BookSyncMerchant::where('posting_token', $merchantToken)
            ->where('client_id', $client->id)
            ->first();

        if (! $merchant) {
            return response()->json(['error' => 'Invalid merchant token or merchant not found.'], 404);
        }

        if (! $merchant->signing_secret) {
            return response()->json([
                'error'  => 'Merchant has no signing secret.',
                'detail' => 'This merchant was created before HMAC signing was introduced. Re-create the merchant to obtain a signing_secret.',
            ], 401);
        }

        $authError = $this->verifySignature($request, $merchant);
        if ($authError !== null) {
            return response()->json($authError, 401);
        }

        if ($merchant->status !== 'active') {
            return response()->json([
                'error'  => 'Merchant is not active.',
                'status' => $merchant->status,
                'detail' => match ($merchant->status) {
                    'pending_qb_connect' => 'The merchant has not yet connected QuickBooks. Share the setup link.',
                    'qb_token_expired'   => 'The merchant\'s QuickBooks token has expired. The merchant must reconnect.',
                    'disabled'           => 'This merchant has been disabled.',
                    default              => 'Merchant cannot accept transactions at this time.',
                },
            ], 403);
        }

        ['batch' => $batch, 'duplicates' => $duplicates] = $this->batches->process($merchant, $request->validated());

        $statusCode = ($batch->skipped === $batch->total_transactions) ? 409 : 200;

        return response()->json($this->batches->formatResponse($batch, $duplicates), $statusCode);
    }

    /** GET /booksync/api/v1/batches/{batch_id} */
    public function show(Request $request, string $batchId): JsonResponse
    {
        /** @var BookSyncClient $client */
        $client = $request->attributes->get('booksync_client');

        $batch = BookSyncBatch::where('batch_id', $batchId)
            ->whereHas('merchant', fn ($q) => $q->where('client_id', $client->id))
            ->with('transactions', 'merchant')
            ->first();

        if (! $batch) {
            return response()->json(['error' => 'Batch not found.'], 404);
        }

        $batch->recalculateCounts();

        return response()->json($this->batches->formatResponse($batch->fresh('transactions')));
    }

    /**
     * Verify X-BookSync-Timestamp + X-BookSync-Signature.
     *
     * Signed payload  = "{timestamp}.{raw_body}"
     * Signature header = "sha256=<HMAC-SHA256(signed_payload, signing_secret)>"
     *
     * During the 30-minute rotation grace period, the previous secret is also accepted.
     *
     * Returns null on success, or an error array on failure.
     */
    private function verifySignature(Request $request, BookSyncMerchant $merchant): ?array
    {
        $timestamp = $request->header('X-BookSync-Timestamp');
        $signature = $request->header('X-BookSync-Signature');

        if (! $timestamp) {
            return [
                'error'  => 'Missing X-BookSync-Timestamp header.',
                'detail' => 'Include the current Unix timestamp (seconds) in X-BookSync-Timestamp.',
            ];
        }

        if (! is_numeric($timestamp)) {
            return ['error' => 'X-BookSync-Timestamp must be a Unix timestamp integer.'];
        }

        $drift = abs(time() - (int) $timestamp);
        if ($drift > self::TIMESTAMP_TOLERANCE_SECONDS) {
            return [
                'error'  => 'Request timestamp is too old or too far in the future.',
                'detail' => "Timestamp drift is {$drift}s; maximum allowed is " . self::TIMESTAMP_TOLERANCE_SECONDS . 's.',
            ];
        }

        if (! $signature) {
            return [
                'error'  => 'Missing X-BookSync-Signature header.',
                'detail' => 'Compute sha256=HMAC-SHA256("{timestamp}.{raw_body}", signing_secret) and include in X-BookSync-Signature.',
            ];
        }

        $signedPayload = $timestamp . '.' . $request->getContent();

        // Verify against current secret
        $expected = 'sha256=' . hash_hmac('sha256', $signedPayload, $merchant->signing_secret);
        if (hash_equals($expected, $signature)) {
            return null;
        }

        // During rotation grace period, also accept the previous secret
        if (
            $merchant->previous_signing_secret
            && $merchant->previous_secret_expires_at
            && $merchant->previous_secret_expires_at->isFuture()
        ) {
            $expectedPrev = 'sha256=' . hash_hmac('sha256', $signedPayload, $merchant->previous_signing_secret);
            if (hash_equals($expectedPrev, $signature)) {
                return null;
            }
        }

        return ['error' => 'Invalid signature.'];
    }
}
