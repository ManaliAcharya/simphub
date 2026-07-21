<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Auth\Services\ReauthenticationService;

class ReauthenticationController extends Controller
{
    public function __construct(
        private readonly ReauthenticationService $reauthenticationService
    ) {}

    public function show(): View
    {
        return view('auth::reauthenticate');
    }

    public function verify(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $clientAccount = $request->attributes->get('client_account');
        $session = $request->attributes->get('merchant_session');

        abort_if(! $clientAccount || ! $session, 401);

        $this->reauthenticationService->verify(
            clientAccount: $clientAccount,
            session: $session,
            password: $validated['password'],
            ipAddress: $request->ip()
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Re-authentication successful.',
                'expires_in_seconds' => config('rate_limits.reauth.window_minutes', 5) * 60,
            ]);
        }

        return redirect(
            clientConfigUrl($session->clientAccount->owner)
        );
    }
}
