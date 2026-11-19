<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Modules\Boarding\Models\BoardingClient;
use Modules\Inbound\Models\Client;

// `role` is deliberately not fillable: it's only ever set explicitly
// (forceFill in the admin:create command) so request data can't escalate it.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'role' => UserRole::class,
        ];
    }

    public function isAgent(): bool
    {
        return $this->role === UserRole::Agent;
    }

    /**
     * May this user open a "view as client" (impersonation) session on $client?
     * This is the single rule for it — the route gate, the controller and the
     * clients-list button all use it.
     *
     *  - Agent: only their own clients. The super-admin flag doesn't widen this,
     *    so agents never need (and shouldn't be given) is_super_admin.
     *  - Admin: only when flagged super-admin (unchanged behaviour).
     */
    public function canViewAsClient(Client|BoardingClient|null $client): bool
    {
        if (! $client) {
            return false;
        }

        if ($this->isAgent()) {
            return $client->agent_id !== null && (int) $client->agent_id === (int) $this->getKey();
        }

        return (bool) $this->is_super_admin;
    }

    /** Inbound clients assigned to this user as their agent. */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'agent_id');
    }

    /** Boarding (ISO) clients assigned to this user as their agent. */
    public function boardingClients(): HasMany
    {
        return $this->hasMany(BoardingClient::class, 'agent_id');
    }
}
