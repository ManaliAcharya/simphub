<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Auth\Services\MerchantSessionService;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfMerchantAuthenticated
{
    public function __construct(
        private readonly MerchantSessionService $merchantSessionService
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $rawSessionToken = $request->cookie('merchant_session');

        if (! $rawSessionToken) {
            return $next($request);
        }

        $session = $this->merchantSessionService->resolveSession(
            rawSessionToken: $rawSessionToken,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id')
        );

        if (
            $session &&
            $session->clientAccount &&
            $session->clientAccount->owner
        ) {
            return redirect(
                clientConfigUrl($session->clientAccount->owner)
            );
        }

        return $next($request);
    }
}
