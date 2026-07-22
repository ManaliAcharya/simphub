<?php

namespace Modules\Boarding\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Boarding\Models\BoardingClient;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBoardingClientApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer) {
            return response()->json(['error' => 'Missing Authorization header.'], 401);
        }

        $client = BoardingClient::where('status', 'active')->get()
            ->first(fn (BoardingClient $c) => hash_equals($c->client_api_key, $bearer));

        if (! $client) {
            return response()->json(['error' => 'Invalid or inactive API key.'], 401);
        }

        $request->attributes->set('boarding_client', $client);

        return $next($request);
    }
}
