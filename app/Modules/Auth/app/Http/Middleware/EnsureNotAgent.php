<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to full admins: agent accounts get a 403. Use it for
 * admin areas whose records can't be assigned to an agent (e.g. BookSync
 * clients, the cross-client API log), where an agent would otherwise see
 * every client's data.
 */
class EnsureNotAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(
            ! Auth::check() || Auth::user()->isAgent(),
            403,
            'This area is available to admins only.'
        );

        return $next($request);
    }
}
