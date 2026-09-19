<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\View;
use Modules\Auth\Services\MerchantSessionService;
use Symfony\Component\HttpFoundation\Response;

class MerchantSessionMiddleware
{
    public function __construct(
        private readonly MerchantSessionService $merchantSessionService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $rawSessionToken = $request->cookie('merchant_session');

        if (! $rawSessionToken) {
            return $this->unauthenticated($request);
        }

        $session = $this->merchantSessionService->resolveSession(
            rawSessionToken: $rawSessionToken,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->attributes->get('request_id')
        );

        if (! $session) {
            Cookie::queue(Cookie::forget('merchant_session'));

            return $this->unauthenticated($request);
        }

        $owner = $session?->clientAccount?->owner;
        $clientAccount = $session->clientAccount;

        // Check query string
        if ($request->filled('pms_client_id')) {

            abort_if(
                $request->pms_client_id != $owner->pms_client_id,
                403,
                'Unauthorized client access'
            );
        }

        // Check route parameters
        if ($request->route('pms_client_id')) {

            abort_if(
                $request->route('pms_client_id') != $owner->pms_client_id,
                403,
                'Unauthorized client access'
            );
        }

        $isImpersonating = (bool) $session->is_impersonation;

        // A super-admin's read-only "view as client" session: allow safe
        // (GET/HEAD/OPTIONS) requests through so every existing portal
        // screen renders normally, but block anything that would change
        // data. See ImpersonationService for how these sessions are created.
        if ($isImpersonating && ! $request->isMethodSafe()) {
            abort(403, 'This is a read-only impersonated session — changes cannot be made while viewing as a client.');
        }

        $request->attributes->set('merchant_session', $session);
        $request->attributes->set('client_account', $session->clientAccount);
        $request->attributes->set('is_impersonating', $isImpersonating);

        View::share('clientAccount', $session->clientAccount);
        View::share('isImpersonating', $isImpersonating);
        View::share('impersonationAdmin', $isImpersonating ? $session->impersonatedByAdmin : null);

        $response = $next($request);

        $this->merchantSessionService->touchLastActivity($session);

        return $response;
    }

    private function unauthenticated(Request $request): Response
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($request->isMethod('GET')) {
            session(['url.intended' => $request->getRequestUri()]);
        }

        return redirect()->route('auth.login');
    }
}
