<?php

namespace App\Enums;

/**
 * Role of an internal (App\Models\User) account that logs in at /admin/login.
 *
 *  - Admin: sees and manages every client (the original behaviour).
 *  - Agent: same abilities as an admin, but only over the clients assigned to
 *    them (clients.agent_id / boarding_clients.agent_id). Clients an agent
 *    creates are assigned to that agent automatically.
 *
 * Super-admin (users.is_super_admin) is a separate capability flag on top of
 * the role, not a role of its own.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Agent = 'agent';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Agent => 'Agent',
        };
    }
}
