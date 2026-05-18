<?php

namespace Modules\Inbound\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Inbound\Models\Client;
use Symfony\Component\HttpFoundation\Response;

class CrmApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'error' => ['code' => 'invalid_api_key', 'message' => 'Missing Authorization header.'],
            ], 401);
        }

        $client = Client::query()->where('pms_client_id', $token)->first();

        if (! $client) {
            return response()->json([
                'error' => ['code' => 'invalid_api_key', 'message' => 'Invalid or revoked API key.'],
            ], 401);
        }

        $request->attributes->set('crm_client', $client);

        return $next($request);
    }
}
