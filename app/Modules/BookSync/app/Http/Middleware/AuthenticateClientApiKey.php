<?php

namespace Modules\BookSync\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\BookSync\Models\BookSyncClient;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateClientApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer) {
            return response()->json(['error' => 'Missing Authorization header.'], 401);
        }

        $client = BookSyncClient::where('status', 'active')->get()
            ->first(fn (BookSyncClient $c) => hash_equals($c->client_api_key, $bearer));

        if (! $client) {
            return response()->json(['error' => 'Invalid or inactive API key.'], 401);
        }

        $request->attributes->set('booksync_client', $client);

        return $next($request);
    }
}
