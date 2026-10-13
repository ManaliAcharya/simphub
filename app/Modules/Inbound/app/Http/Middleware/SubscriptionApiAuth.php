<?php

namespace Modules\Inbound\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Inbound\Models\Client;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates subscription API calls with the client's dedicated subscription
 * API key (not the public pms_client_id). Keys are looked up by SHA-256 hash.
 */
class SubscriptionApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = trim((string) $request->bearerToken());

        if ($token === '') {
            return $this->unauthorized('Missing Authorization header.');
        }

        $client = Client::query()
            ->where('subscription_api_key_hash', Client::hashSubscriptionApiKey($token))
            ->where('client_pms', 'ZOHOCRM')
            ->first();

        if (! $client) {
            return $this->unauthorized('Invalid or revoked API key.');
        }

        $request->attributes->set('subscription_client', $client);

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 401);
    }
}
