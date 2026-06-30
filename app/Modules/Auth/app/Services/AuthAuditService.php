<?php

namespace Modules\Auth\Services;

use Illuminate\Support\Str;
use Modules\Auth\Enums\AuditEventType;
use Modules\Auth\Repositories\AuthAuditRepository;

class AuthAuditService
{
    public function __construct(
        private readonly AuthAuditRepository $authAuditRepository
    ) {}

    public function log(array $data): void
    {
        $this->authAuditRepository->create([
            'client_account_id' => $data['client_account_id'] ?? null,
            'event_type' => $data['event_type'],
            'outcome' => $data['outcome'],
            'failure_reason' => $data['failure_reason'] ?? null,
            'ip_address' => $data['ip_address'] ?? request()->ip(),
            'user_agent' => $data['user_agent'] ?? request()->userAgent(),
            'request_id' => $data['request_id'] ?? $this->requestId(),
            'metadata' => $data['metadata'] ?? null,
            'created_at' => now(),
        ]);
    }

    private function requestId(): string
    {
        return request()->headers->get('X-Request-Id')
            ?: (string) Str::uuid();
    }

    public function countEvents(string $clientAccountId, AuditEventType $eventType, int $hours = 24): int
    {
        return $this->authAuditRepository->countEvents($clientAccountId, $eventType, $hours);
    }
}
