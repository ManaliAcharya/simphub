<?php

namespace Modules\BookSync\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\BookSync\Models\BookSyncApiLog;
use Modules\BookSync\Models\BookSyncClient;
use Modules\BookSync\Models\BookSyncMerchant;

class BookSyncApiLogger
{
    /**
     * Record an inbound posting request and its outcome.
     * Always succeeds — any DB/exception is swallowed so logging never breaks a live request.
     */
    public function logPost(
        Request              $request,
        JsonResponse         $response,
        ?BookSyncClient      $client   = null,
        ?BookSyncMerchant    $merchant = null,
        ?string              $batchId  = null,
        ?string              $rejectionReason = null,
    ): void {
        try {
            BookSyncApiLog::create([
                'client_id'        => $client?->id,
                'merchant_id'      => $merchant?->id,
                'ip_address'       => $request->ip() ?? 'unknown',
                'path'             => $request->path(),
                'request_body'     => $request->getContent() ?: null,
                'timestamp_header' => $request->header('X-BookSync-Timestamp'),
                'signature_header' => $request->header('X-BookSync-Signature'),
                'http_status'      => $response->getStatusCode(),
                'rejection_reason' => $rejectionReason,
                'batch_id'         => $batchId,
                'created_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('BookSync: failed to write API audit log', ['error' => $e->getMessage()]);
        }
    }
}
