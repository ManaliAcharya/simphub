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

        // 409 when every transaction in the batch was already posted
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
}
