<?php

namespace Modules\Auth\Repositories;

use App\Repositories\BaseRepository;
use Modules\Auth\Enums\AuditEventType;
use Modules\Auth\Models\AuthAudit;

class AuthAuditRepository extends BaseRepository
{

    public function __construct(AuthAudit $model)
    {
        parent::__construct($model);
    }

    public function countEvents(string $clientAccountId, AuditEventType $eventType, int $hours = 24): int
    {

        return $this->model
            ->where('client_account_id', $clientAccountId)
            ->where('event_type', $eventType->value)
            ->where(
                'created_at',
                '>=',
                now()->subHours($hours)
            )
            ->count();
    }
}
