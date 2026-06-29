<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Inbound\Models\QuickBooksConnection;
use Modules\Inbound\Services\PmsOAuthStateService;
use Modules\Inbound\Services\QuickBooksOAuthService;
use Throwable;

class QuickBooksAuthController extends Controller
{
    public function callback(
        Request $request,
        QuickBooksOAuthService $oauth,
        PmsOAuthStateService $state,
    ): RedirectResponse {
        $pmsClientId = '';

        try {
            if ($request->filled('state')) {
                $payload     = $state->validate($request->query('state'));
                $pmsClientId = (string) ($payload['pms_client_id'] ?? '');
            }
        } catch (Throwable) {
        }

        if ($request->filled('error')) {
            return redirect()->route('inbound.quickbooks.page', [
                'error'         => $request->string('error_description')->toString() ?: $request->string('error')->toString(),
                'pms_client_id' => $pmsClientId,
            ]);
        }

        try {
            $payload     = $state->validate($request->query('state'));
            $pmsClientId = (string) ($payload['pms_client_id'] ?? '');

            abort_unless(($payload['provider'] ?? null) === 'quickbooks', 422, 'OAuth state provider mismatch.');

            $realmId    = (string) $request->query('realmId', '');
            $tokenData  = $oauth->exchangeCodeTokens((string) $request->query('code'));
            $companies  = $oauth->fetchCompanies((string) ($tokenData['access_token'] ?? ''));

            // Single company (or fetch failed) — bind immediately using the callback realmId
            if (count($companies) <= 1) {
                $selectedRealm = $companies[0]['realmId'] ?? $realmId;
                $oauth->createConnection($tokenData, $selectedRealm ?: $realmId, $pmsClientId);

                return redirect()->route('inbound.quickbooks.page', [
                    'success'       => 'QuickBooks connected successfully.',
                    'pms_client_id' => $pmsClientId,
                    'realm_id'      => $selectedRealm ?: $realmId,
                ]);
            }

            // Multiple companies — store pending auth in session and show picker
            session([
                'qb_pending_tokens'      => $tokenData,
                'qb_pending_pms_client'  => $pmsClientId,
                'qb_pending_companies'   => $companies,
                'qb_callback_realm_id'   => $realmId,
            ]);

            return redirect()->route('inbound.quickbooks.select-company');
        } catch (Throwable $exception) {
            return redirect()->route('inbound.quickbooks.page', [
                'error'         => $exception->getMessage(),
                'pms_client_id' => $pmsClientId,
            ]);
        }
    }

    public function selectCompany(Request $request): View|RedirectResponse
    {
        $companies   = session('qb_pending_companies', []);
        $pmsClientId = session('qb_pending_pms_client', '');
        $callbackRealm = session('qb_callback_realm_id', '');

        if (empty($companies) || $pmsClientId === '') {
            return redirect()->route('inbound.quickbooks.page', [
                'error'         => 'No pending QuickBooks connection found. Please start the OAuth flow again.',
                'pms_client_id' => $pmsClientId,
            ]);
        }

        return view('inbound::quickbooks.select-company', [
            'companies'     => $companies,
            'pms_client_id' => $pmsClientId,
            'callbackRealm' => $callbackRealm,
        ]);
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $pmsClientId = $request->validate([
            'pms_client_id' => ['required', 'string'],
        ])['pms_client_id'];

        $connection = QuickBooksConnection::query()
            ->where('provider', 'quickbooks')
            ->where('pms_client_id', $pmsClientId)
            ->first();

        if ($connection) {
            $connection->forceFill([
                'access_token'     => null,
                'refresh_token'    => null,
                'token_expires_at' => null,
                'last_error'       => 'Disconnected by user.',
            ])->save();
        }

        return redirect()->route('inbound.quickbooks.page', [
            'success'       => 'QuickBooks disconnected. Click "Connect QuickBooks" to reconnect.',
            'pms_client_id' => $pmsClientId,
        ]);
    }

    public function confirmCompany(
        Request $request,
        QuickBooksOAuthService $oauth,
    ): RedirectResponse {
        $validated = $request->validate(['realm_id' => ['required', 'string', 'max:100']]);

        $tokenData   = session('qb_pending_tokens', []);
        $pmsClientId = session('qb_pending_pms_client', '');
        $companies   = session('qb_pending_companies', []);

        if (empty($tokenData) || $pmsClientId === '') {
            return redirect()->route('inbound.quickbooks.page', [
                'error'         => 'Session expired. Please start the QuickBooks OAuth flow again.',
                'pms_client_id' => $pmsClientId,
            ]);
        }

        $validRealms = array_column($companies, 'realmId');
        if (! in_array($validated['realm_id'], $validRealms, true)) {
            return redirect()->route('inbound.quickbooks.select-company')
                ->withErrors(['realm_id' => 'Invalid company selected.']);
        }

        try {
            $oauth->createConnection($tokenData, $validated['realm_id'], $pmsClientId);

            session()->forget(['qb_pending_tokens', 'qb_pending_pms_client', 'qb_pending_companies', 'qb_callback_realm_id']);

            return redirect()->route('inbound.quickbooks.page', [
                'success'       => 'QuickBooks connected successfully.',
                'pms_client_id' => $pmsClientId,
                'realm_id'      => $validated['realm_id'],
            ]);
        } catch (Throwable $exception) {
            return redirect()->route('inbound.quickbooks.page', [
                'error'         => $exception->getMessage(),
                'pms_client_id' => $pmsClientId,
            ]);
        }
    }
}
