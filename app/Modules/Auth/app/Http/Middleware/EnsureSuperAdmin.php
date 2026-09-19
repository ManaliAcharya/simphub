<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to admin (App\Models\User) accounts flagged as
 * super-admins — currently used to gate starting a client impersonation
 * session. There is no broader roles/permissions layer yet, so this is a
 * single boolean flag (`users.is_super_admin`) rather than a role check.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            Auth::check() && (bool) Auth::user()->is_super_admin,
            403,
            'This action requires super-admin access.'
        );

        return $next($request);
    }
}
