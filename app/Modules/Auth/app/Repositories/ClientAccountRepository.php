<?php

namespace Modules\Auth\Repositories;

use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Model;
use Modules\Auth\Models\ClientAccount;

class ClientAccountRepository extends BaseRepository
{

    public function __construct(ClientAccount $model)
    {
        parent::__construct($model);
    }
    public function create(array $data): ClientAccount
    {
        return $this->model->create($data);
    }

    public function update(Model $model, array $data): bool
    {
        return $model->update($data);
    }

    public function updateById(int|string $id, array $data): bool
    {
        return $this->model
            ->newQuery()
            ->whereKey($id)
            ->update($data) > 0;
    }

    /**
     * Find active client account by email.
     */
    public function findActiveByEmail(string $email): ?ClientAccount
    {
        return $this->model
            ->newQuery()
            ->where('email_lower', strtolower(trim($email)))
            ->where('is_active', true)
            ->where('is_suspended', false)
            ->first();
    }

    public function findByEmail(string $email)
    {
        return $this->model
            ->where('email_lower', $email)
            ->first();
    }

    public function findByOwner(string $ownerType, string $ownerId): ?ClientAccount
    {
        return $this->model::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->first();
    }
}
