<?php

namespace Modules\Audit\Services;

use Illuminate\Support\Facades\Log;
use Modules\Audit\Models\AuditLog;

class AuditLogger
{
    public static function log(
        string $eventType,
        string $entityType,
        string $entityId,
        array $payload = [],
        string $actorType = 'system'
    ): void
    {
        AuditLog::query()->create([
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'actor_type' => $actorType,
            'payload' => $payload,
            'ip_address' => request()?->ip(),
            'trace_id' => app(TraceContext::class)->current(),
        ]);

        Log::info($eventType, [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'actor_type' => $actorType,
            'payload' => $payload,
        ]);
    }
}
