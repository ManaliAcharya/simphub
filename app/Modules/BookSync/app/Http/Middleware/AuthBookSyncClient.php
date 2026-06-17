<?php

namespace Modules\BookSync\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthBookSyncClient
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! Auth::guard('booksync_client')->check()) {
            return redirect()->route('booksync.portal.login')
                ->with('redirect_to', $request->url());
        }

        return $next($request);
    }
}
