<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For client models that can be assigned to an agent through an `agent_id`
 * column (Inbound Client, BoardingClient).
 *
 * Visibility is applied explicitly at the admin-side entry points
 * (`Client::visibleTo($user)`) rather than through a global scope: a global
 * scope keyed on the logged-in admin would also hide clients from public
 * payment pages, webhooks and the merchant portal whenever an agent happened
 * to be logged in on the same browser.
 */
trait AssignableToAgent
{
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Limit the query to the clients $user may see: everything for an admin,
     * only their own clients for an agent, nothing for a guest.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isAgent()) {
            return $query->where($this->qualifyColumn('agent_id'), $user->getKey());
        }

        return $query;
    }

    public function isVisibleTo(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return ! $user->isAgent() || (int) $this->agent_id === (int) $user->getKey();
    }
}
