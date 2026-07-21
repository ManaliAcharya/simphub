<?php

namespace Modules\Auth\Services;

use Illuminate\Support\Facades\Hash;
use Modules\Auth\Models\ClientAccount;
use Modules\Auth\Repositories\ClientAccountRepository;

class ClientAccountService
{
    private ClientAccountRepository $clientAccountRepository;

    public function __construct(ClientAccountRepository $clientAccountRepository)
    {
        $this->clientAccountRepository = $clientAccountRepository;
    }

    public function createClientAccount(array $data): ClientAccount
    {
        $preparedData = $this->prepareclientAccount($data);

        return $this->clientAccountRepository->create($preparedData);
    }

    protected function prepareclientAccount(array $data): array
    {
        $owner = $data['owner'];

        return [
            'owner_type' => $owner::class,
            'owner_id' => $owner->getKey(),
            'email' => $data['email'],
            'email_lower' => strtolower($data['email']),
            'password_hash' => null,
        ];
    }

    public function updatePasswordAndLoginInfo(ClientAccount $account, array $data): bool
    {
        return $this->clientAccountRepository->update($account, [
            'password_hash' => Hash::make($data['password'], [
                'memory' => 65536,
                'time' => 3,
                'threads' => 1,
            ]),
            'password_set_at' => now(),
            'last_login_at' => now(),
            'last_login_ip' => $data['ip_address'] ?? null,
            'last_login_ua' => $data['user_agent'] ?? null,
        ]);
    }

    public function updateLoginInfo(ClientAccount $account, array $data): bool
    {
        return $this->clientAccountRepository->update($account, [
            'last_login_at' => now(),
            'last_login_ip' => $data['ip_address'] ?? null,
            'last_login_ua' => $data['user_agent'] ?? null,
        ]);
    }

    public function suspendAccount($account, string $reason): void
    {
        $account->update([
            'is_suspended' => true,
            'suspended_reason' => $reason,
            'suspended_at' => now(),

        ]);
    }

    public function findByOwner(object $owner): ClientAccount|null
    {
        return $this->clientAccountRepository->findByOwner($owner::class, $owner->getKey());
    }
}
