<?php

namespace Modules\Auth\Repositories;

use App\Repositories\BaseRepository;
use Modules\Auth\Enums\OperationOutcome;
use Modules\Auth\Models\LoginAttempt;

class LoginAttemptRepository extends BaseRepository
{

    public function __construct(LoginAttempt $model)
    {
        parent::__construct($model);
    }

    public function countRecentFailuresByEmail(string $email, int $minutes): int
    {
        return $this->model
            ->newQuery()
            ->where('email_lower', strtolower(trim($email)))
            ->where('outcome', OperationOutcome::FAILURE->value)
            ->where('attempted_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    public function countRecentFailuresByIp(string $ipAddress, int $minutes): int
    {
        return $this->model
            ->newQuery()
            ->where('ip_address', $ipAddress)
            ->where('outcome', OperationOutcome::FAILURE->value)
            ->where('attempted_at', '>=', now()->subMinutes($minutes))
            ->count();
    }
}
