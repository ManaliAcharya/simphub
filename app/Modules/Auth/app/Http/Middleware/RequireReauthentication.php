<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Auth\Services\MerchantSessionService;
use Symfony\Component\HttpFoundation\Response;

class RequireReauthentication
{
    public function __construct(
        private readonly MerchantSessionService $merchantSessionService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->attributes->get('merchant_session');

        if (!$this->merchantSessionService->hasValidReauthentication($session)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'reauth_required' => true,
                    'message' => 'Please re-enter your password to continue.',
                ], 403);
            }

            session([
                'url.intended' => $request->fullUrl(),
            ]);

            return redirect()->route('auth.reauthenticate.form');
        }

        return $next($request);
    }
}
