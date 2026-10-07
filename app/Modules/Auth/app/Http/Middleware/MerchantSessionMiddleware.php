<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\View;
use Modules\Audit\Services\AuditLogger;
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

        // A super-admin's "view as client" session can edit the client's config,
        // but never the client's own login: anything gated behind a password
        // re-prompt (email, password, session management) stays blocked, as does
        // the re-prompt itself. See ImpersonationService for how these sessions
        // are created.
        $isImpersonatedWrite = $isImpersonating && ! $request->isMethodSafe();

        if ($isImpersonatedWrite && (
            in_array('reauth.required', $request->route()?->gatherMiddleware() ?? [], true)
            || $request->routeIs('*auth.reauthenticate')
        )) {
            abort(403, 'Account login settings cannot be changed while viewing as a client.');
        }

        $request->attributes->set('merchant_session', $session);
        $request->attributes->set('client_account', $session->clientAccount);
        $request->attributes->set('is_impersonating', $isImpersonating);

        View::share('clientAccount', $session->clientAccount);
        View::share('isImpersonating', $isImpersonating);
        View::share('impersonationAdmin', $isImpersonating ? $session->impersonatedByAdmin : null);

        $response = $next($request);

        // Every change an admin makes on a client's behalf is attributed to them.
        // Only the route is logged, never the request body — it can carry
        // gateway credentials.
        if ($isImpersonatedWrite) {
            AuditLogger::log(
                eventType: 'IMPERSONATION_WRITE',
                entityType: 'client_account',
                entityId: $clientAccount->id,
                payload: [
                    'admin_user_id' => $session->impersonated_by_user_id,
                    'merchant_session_id' => $session->id,
                    'method' => $request->method(),
                    'route' => $request->route()?->getName(),
                    'path' => $request->path(),
                    'status' => $response->getStatusCode(),
                ],
                actorType: 'admin'
            );
        }

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
