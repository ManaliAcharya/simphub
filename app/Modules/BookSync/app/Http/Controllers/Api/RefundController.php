<?php

namespace Modules\BookSync\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\BookSync\Http\Requests\RefundRequest;
use Modules\BookSync\Http\Requests\VoidRequest;
use Modules\BookSync\Models\BookSyncClient;
use Modules\BookSync\Models\BookSyncMerchant;
use Modules\BookSync\Services\BookSyncApiLogger;
use Modules\BookSync\Services\BookSyncRefundService;

class RefundController extends Controller
{
    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public function __construct(
        private readonly BookSyncRefundService $refunds,
        private readonly BookSyncApiLogger     $logger,
    ) {}

    /** POST /booksync/refund/{merchantToken} */
    public function refund(RefundRequest $request, string $merchantToken): JsonResponse
    {
        /** @var BookSyncClient $client */
        $client   = $request->attributes->get('booksync_client');
        $merchant = null;

        $response = $this->handleRefund($request, $merchantToken, $client, $merchant);

        $this->logger->logPost(
            request:         $request,
            response:        $response,
            client:          $client,
            merchant:        $merchant,
            rejectionReason: $response->getStatusCode() < 400
                ? null
                : data_get(json_decode($response->getContent(), true), 'rejection_reason'),
        );

        return $response;
    }

    /** POST /booksync/void/{merchantToken} */
    public function void(VoidRequest $request, string $merchantToken): JsonResponse
    {
        /** @var BookSyncClient $client */
        $client   = $request->attributes->get('booksync_client');
        $merchant = null;

        $response = $this->handleVoid($request, $merchantToken, $client, $merchant);

        $this->logger->logPost(
            request:         $request,
            response:        $response,
            client:          $client,
            merchant:        $merchant,
            rejectionReason: $response->getStatusCode() < 400
                ? null
                : data_get(json_decode($response->getContent(), true), 'rejection_reason'),
        );

        return $response;
    }

    private function handleRefund(
        RefundRequest     $request,
        string            $merchantToken,
        BookSyncClient    $client,
        ?BookSyncMerchant &$merchant,
    ): JsonResponse {
        $authError = $this->authenticate($request, $merchantToken, $client, $merchant);
        if ($authError !== null) {
            return $authError;
        }

        $results = [];
        $posted  = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($request->validated()['refunds'] as $refundData) {
            try {
                $result = $this->refunds->createRefund($merchant, $refundData);
                if ($result['status'] === 'already_posted') {
                    $skipped++;
                } else {
                    $posted++;
                }
                $results[] = $result;
            } catch (\InvalidArgumentException $e) {
                $failed++;
                $results[] = [
                    'reference' => $refundData['reference'] ?? null,
                    'status'    => 'failed',
                    'error'     => $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                $failed++;
                $results[] = [
                    'reference' => $refundData['reference'] ?? null,
                    'status'    => 'failed',
                    'error'     => $e->getMessage(),
                ];
            }
        }

        $total = count($results);

        return response()->json([
            'total'   => $total,
            'posted'  => $posted,
            'skipped' => $skipped,
            'failed'  => $failed,
            'results' => $results,
        ]);
    }

    private function handleVoid(
        VoidRequest       $request,
        string            $merchantToken,
        BookSyncClient    $client,
        ?BookSyncMerchant &$merchant,
    ): JsonResponse {
        $authError = $this->authenticate($request, $merchantToken, $client, $merchant);
        if ($authError !== null) {
            return $authError;
        }

        $results      = [];
        $voided       = 0;
        $skipped      = 0;
        $failed       = 0;

        foreach ($request->validated()['voids'] as $voidData) {
            $ref = $voidData['original_reference'];
            try {
                $result = $this->refunds->voidTransaction($merchant, $ref);
                if ($result['status'] === 'already_voided') {
                    $skipped++;
                } else {
                    $voided++;
                }
                $results[] = $result;
            } catch (\InvalidArgumentException $e) {
                $failed++;
                $results[] = [
                    'original_reference' => $ref,
                    'status'             => 'failed',
                    'error'              => $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                $failed++;
                $results[] = [
                    'original_reference' => $ref,
                    'status'             => 'failed',
                    'error'              => $e->getMessage(),
                ];
            }
        }

        $total = count($results);

        return response()->json([
            'total'   => $total,
            'voided'  => $voided,
            'skipped' => $skipped,
            'failed'  => $failed,
            'results' => $results,
        ]);
    }

    /**
     * Resolve the merchant and verify HMAC signature.
     * Returns a JsonResponse on auth failure, null on success.
     * $merchant is populated by reference on success.
     */
    private function authenticate(
        Request           $request,
        string            $merchantToken,
        BookSyncClient    $client,
        ?BookSyncMerchant &$merchant,
    ): ?JsonResponse {
        $merchant = BookSyncMerchant::where('posting_token', $merchantToken)
            ->where('client_id', $client->id)
            ->first();

        if (! $merchant) {
            return $this->err([
                'error'            => 'Invalid merchant token or merchant not found.',
                'rejection_reason' => 'merchant_not_found',
            ], 404);
        }

        if (! $merchant->signing_secret) {
            return $this->err([
                'error'            => 'Merchant has no signing secret.',
                'detail'           => 'Re-create the merchant to obtain a signing_secret.',
                'rejection_reason' => 'no_signing_secret',
            ], 401);
        }

        $sigError = $this->verifySignature($request, $merchant);
        if ($sigError !== null) {
            return $this->err($sigError, 401);
        }

        if ($merchant->status !== 'active') {
            return $this->err([
                'error'            => 'Merchant is not active.',
                'status'           => $merchant->status,
                'rejection_reason' => 'merchant_not_active',
                'detail'           => match ($merchant->status) {
                    'pending_qb_connect' => 'The merchant has not yet connected QuickBooks.',
                    'qb_token_expired'   => 'The merchant\'s QuickBooks token has expired. The merchant must reconnect.',
                    'disabled'           => 'This merchant has been disabled.',
                    default              => 'Merchant cannot accept transactions at this time.',
                },
            ], 403);
        }

        return null;
    }

    private function verifySignature(Request $request, BookSyncMerchant $merchant): ?array
    {
        $timestamp = $request->header('X-BookSync-Timestamp');
        $signature = $request->header('X-BookSync-Signature');

        if (! $timestamp) {
            return [
                'error'            => 'Missing X-BookSync-Timestamp header.',
                'detail'           => 'Include the current Unix timestamp (seconds) in X-BookSync-Timestamp.',
                'rejection_reason' => 'timestamp_missing',
            ];
        }

        if (! is_numeric($timestamp)) {
            return [
                'error'            => 'X-BookSync-Timestamp must be a Unix timestamp integer.',
                'rejection_reason' => 'timestamp_invalid',
            ];
        }

        $drift = abs(time() - (int) $timestamp);
        if ($drift > self::TIMESTAMP_TOLERANCE_SECONDS) {
            return [
                'error'            => 'Request timestamp is too old or too far in the future.',
                'detail'           => "Timestamp drift is {$drift}s; maximum allowed is " . self::TIMESTAMP_TOLERANCE_SECONDS . 's.',
                'rejection_reason' => 'timestamp_stale',
            ];
        }

        if (! $signature) {
            return [
                'error'            => 'Missing X-BookSync-Signature header.',
                'detail'           => 'Compute sha256=HMAC-SHA256("{timestamp}.{raw_body}", signing_secret) and include in X-BookSync-Signature.',
                'rejection_reason' => 'signature_missing',
            ];
        }

        $signedPayload = $timestamp . '.' . $request->getContent();
        $expected      = 'sha256=' . hash_hmac('sha256', $signedPayload, $merchant->signing_secret);

        if (hash_equals($expected, $signature)) {
            return null;
        }

        // Accept previous secret during rotation grace period
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

        return [
            'error'            => 'Invalid signature.',
            'rejection_reason' => 'signature_invalid',
        ];
    }

    private function err(array $body, int $status): JsonResponse
    {
        return response()->json($body, $status);
    }
}
