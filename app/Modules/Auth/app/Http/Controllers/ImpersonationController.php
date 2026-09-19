<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Auth\Models\ClientAccount;
use Modules\Auth\Models\MerchantSession;
use Modules\Auth\Services\ImpersonationService;

/**
 * Admin-only, read-only "view as client" flow — see ImpersonationService
 * for the security model. Gated by 'auth' + 'admin.super' middleware in
 * routes/web.php.
 */
class ImpersonationController extends Controller
{
    public function __construct(
        private readonly ImpersonationService $impersonationService,
    ) {}

    public function start(Request $request, ClientAccount $clientAccount): RedirectResponse
    {
        $result = $this->impersonationService->start(
            admin: Auth::user(),
            clientAccount: $clientAccount,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return redirect(clientConfigUrl($clientAccount->owner))
            ->withCookie(cookie(
                name: 'merchant_session',
                value: $result['raw_session_token'],
                minutes: ImpersonationService::SESSION_DURATION_MINUTES,
                path: '/',
                domain: config('session.domain'),
                secure: config('session.secure', app()->environment('production')),
                httpOnly: true,
                raw: false,
                sameSite: 'Lax',
            ));
    }

    public function stop(Request $request): RedirectResponse
    {
        $rawSessionToken = $request->cookie('merchant_session');

        if ($rawSessionToken) {
            $session = MerchantSession::query()
                ->where('session_token_hash', hash('sha256', $rawSessionToken))
                ->where('is_impersonation', true)
                ->where('impersonated_by_user_id', Auth::id())
                ->whereNull('revoked_at')
                ->first();

            if ($session) {
                $this->impersonationService->end($session);
            }
        }

        return redirect()
            ->route('inbound.clients.index')
            ->withoutCookie('merchant_session');
    }
}
