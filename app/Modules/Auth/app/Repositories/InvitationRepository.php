<?php

namespace Modules\Auth\Repositories;

use Modules\Auth\Models\InvitationToken;

class InvitationRepository
{
    private InvitationToken $model;

    public function __construct(InvitationToken $model)
    {
        $this->model = $model;
    }
    public function createInvitationToken(array $data): InvitationToken
    {
        return $this->model::query()->create($data);
    }

    public function findValidByRawToken(string $rawToken): ?InvitationToken
    {
        return $this->model::query()
            ->with('clientAccount')
            ->where('token_hash', $this->hashToken($rawToken))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function consume(InvitationToken $invitation): bool
    {
        return $invitation->update([
            'consumed_at' => now(),
        ]);
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function findByRawToken(string $rawToken): ?InvitationToken
    {
        return $this->model::query()
            ->with('clientAccount')
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();
    }
}
