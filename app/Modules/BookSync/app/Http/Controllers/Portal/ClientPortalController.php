<?php

namespace Modules\BookSync\Http\Controllers\Portal;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class ClientPortalController extends Controller
{
    public function loginForm()
    {
        if (Auth::guard('booksync_client')->check()) {
            return redirect()->route('booksync.portal.dashboard');
        }

        return view('booksync::portal.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('booksync_client')->attempt([
            'contact_email' => $data['email'],
            'password'      => $data['password'],
            'status'        => 'active',
        ])) {
            return back()
                ->withErrors(['email' => 'Invalid email or password, or account is inactive.'])
                ->withInput($request->only('email'));
        }

        $request->session()->regenerate();

        return redirect()->route('booksync.portal.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('booksync_client')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('booksync.portal.login');
    }

    public function dashboard()
    {
        $client = Auth::guard('booksync_client')->user();
        $client->load('merchants');
        $apiKey = $client->client_api_key;

        return view('booksync::portal.dashboard', compact('client', 'apiKey'));
    }
}
