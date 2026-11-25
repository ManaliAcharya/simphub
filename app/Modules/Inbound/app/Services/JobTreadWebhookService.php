<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Inbound\Jobs\SyncJobTreadConnectionJob;
use Modules\Inbound\Models\JobTreadConnection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives JobTread webhooks at /api/v1/inbound/webhooks/jobtread?pms_client_id=..&token=..
 *
 * JobTread webhooks carry no secret or signature, so the long random token in each client's
 * URL is the only check, and the webhook is a trigger only: it queues a catch-up pass that
 * re-reads documents from JobTread. Nothing in the payload is trusted.
 */
class JobTreadWebhookService
{
    /** Seconds to wait before syncing, so one save's burst of events becomes one pass. */
    private const DEBOUNCE_SECONDS = 10;

    public function handleIncoming(Request $request): Response
    {
        $pmsClientId = (string) $request->query('pms_client_id', '');
        $token       = (string) $request->query('token', '');
        $connection  = JobTreadConnection::forClient($pmsClientId);
        $secret      = (string) ($connection?->webhook_secret ?? '');

        if (! $connection || $secret === '' || $token === '' || ! hash_equals($secret, $token)) {
            return response()->json(['message' => 'Unknown webhook.'], 404);
        }

        $payload     = (array) $request->json()->all();
        $documentIds = self::documentIds($payload);

        // Payload shape and retries aren't documented (first-day test #2): log what arrives.
        Log::info('JobTread webhook received', [
            'pms_client_id' => $pmsClientId,
            'document_ids'  => $documentIds,
            'payload'       => mb_substr((string) json_encode($payload), 0, 4000),
        ]);

        SyncJobTreadConnectionJob::dispatch($pmsClientId, $documentIds)
            ->delay(now()->addSeconds(self::DEBOUNCE_SECONDS));

        return response()->json(['accepted' => true], 202);
    }

    /**
     * Document IDs named anywhere in a webhook payload: a "documentId" key, or the "id" of a
     * "document" object. Unknown shapes give none; the catch-up pass still runs.
     *
     * @return list<string>
     */
    public static function documentIds(array $payload): array
    {
        $ids = [];

        $walk = function (array $node, int $depth) use (&$walk, &$ids): void {
            if ($depth > 6) {
                return;
            }

            foreach ($node as $key => $value) {
                if ($key === 'documentId' && (is_string($value) || is_int($value))) {
                    $ids[] = (string) $value;
                } elseif ($key === 'document' && is_array($value) && isset($value['id']) && is_scalar($value['id'])) {
                    $ids[] = (string) $value['id'];
                }

                if (is_array($value)) {
                    $walk($value, $depth + 1);
                }
            }
        };

        $walk($payload, 0);

        return array_values(array_unique(array_filter($ids, fn (string $id) => $id !== '' && strlen($id) <= 100)));
    }
}
