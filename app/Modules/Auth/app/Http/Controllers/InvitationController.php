<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Auth\Rules\SecurePassword;
use Modules\Auth\Services\InvitationService;

class InvitationController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitationService
    ) {}

    public function show(string $token)
    {
        return view('auth::invitation.accept', $this->invitationService->showInvitation($token));
    }

    public function accept(Request $request)
    {
        $invitationData = $this->invitationService->showInvitation($request->token);

        $validated = $request->validate([
            'token' => ['required', 'string'],
            'password' => [
                'required',
                'confirmed',
                new SecurePassword(
                    email: $invitationData['email'],
                    companyName: $invitationData['client_name'] ?? null
                ),
            ],
        ]);

        $rawSessionToken = $this->invitationService->acceptInvitation([
            ...$validated,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $client = $invitationData['client'];

        $cookie = cookie(
            name: 'merchant_session',
            value: $rawSessionToken,
            minutes: 720,
            path: '/',
            domain: null,
            secure: app()->environment('production'),
            httpOnly: true,
            raw: false,
            sameSite: 'Strict'
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Password created successfully.',
                'redirect_url' => clientConfigUrl($client),
            ])->withCookie($cookie);
        }

        return redirect(clientConfigUrl($client))
            ->withCookie($cookie);
    }
}
