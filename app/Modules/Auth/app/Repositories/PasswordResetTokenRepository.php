<?php
namespace Modules\Auth\Repositories;

use Modules\Auth\Models\PasswordResetToken;

class PasswordResetTokenRepository
{
    public function countRequestsForEmailInLastHour(string $clientAccountId): int
    {
        return PasswordResetToken::where('client_account_id', $clientAccountId)
            ->where('created_at', '>=', now()->subHour())
            ->count();
    }

    public function create(array $data): PasswordResetToken
    {
        return PasswordResetToken::create($data);
    }

    public function latestValidForAccount(string $clientAccountId): ?PasswordResetToken
    {
        return PasswordResetToken::where('client_account_id', $clientAccountId)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    public function consume(PasswordResetToken $token): void
    {
        $token->update([
            'consumed_at' => now(),
        ]);
    }

    public function incrementAttempts(PasswordResetToken $token): PasswordResetToken
    {
        $token->increment('attempts');

        return $token->refresh();
    }
}
