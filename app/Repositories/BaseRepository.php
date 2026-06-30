<?php

namespace App\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

abstract class BaseRepository
{
    protected Model $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * Get fresh query builder.
     */
    protected function query(): Builder
    {
        return $this->model->newQuery();
    }

    /**
     * Get all records.
     */
    public function getAll(array $columns = ['*']): Collection
    {
        return $this->query()->get($columns);
    }

    /**
     * Paginate records.
     */
    public function paginate(int $perPage = 15, array $columns = ['*']): LengthAwarePaginator
    {
        return $this->query()->paginate($perPage, $columns);
    }

    /**
     * Find by primary key.
     */
    public function find(int|string $id): ?Model
    {
        return $this->query()->find($id);
    }

    /**
     * Find by primary key or fail.
     */
    public function findOrFail(int|string $id): Model
    {
        return $this->query()->findOrFail($id);
    }

    /**
     * Find first record matching conditions.
     */
    public function firstWhere(array $conditions): ?Model
    {
        return $this->query()
            ->where($conditions)
            ->first();
    }

    /**
     * Check if record exists.
     */
    public function exists(array $conditions): bool
    {
        return $this->query()
            ->where($conditions)
            ->exists();
    }

    /**
     * Count records.
     */
    public function count(array $conditions = []): int
    {
        return $this->query()
            ->when(
                !empty($conditions),
                fn($query) => $query->where($conditions)
            )
            ->count();
    }

    /**
     * Create record.
     */
    public function create(array $data): Model
    {
        return $this->query()->create($data);
    }

    /**
     * Update model instance.
     */
    public function update(Model $model, array $data): bool
    {
        return $model->update($data);
    }

    /**
     * Update by id.
     */
    public function updateById(
        int|string $id,
        array $data
    ): bool {
        return (bool) $this->query()
            ->whereKey($id)
            ->update($data);
    }

    /**
     * Delete model instance.
     */
    public function delete(Model $model): bool
    {
        return (bool) $model->delete();
    }

    /**
     * Delete by id.
     */
    public function deleteById(int|string $id): bool
    {
        return (bool) $this->query()
            ->whereKey($id)
            ->delete();
    }

    /**
     * First or create.
     */
    public function firstOrCreate(
        array $attributes,
        array $values = []
    ): Model {
        return $this->query()
            ->firstOrCreate($attributes, $values);
    }

    /**
     * Update or create.
     */
    public function updateOrCreate(
        array $attributes,
        array $values = []
    ): Model {
        return $this->query()
            ->updateOrCreate($attributes, $values);
    }
}
