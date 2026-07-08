<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Inbound\Models\AdvancedMdPractice;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Services\AdvancedMdSessionService;

class AdvancedMdIntegrationController extends Controller
{
    public function __construct(
        private readonly AdvancedMdSessionService $session,
    ) {}

    public function show(Request $request): View
    {
        $pmsClientId = (string) $request->query('pms_client_id', '');

        $client = $pmsClientId
            ? Client::query()->where('pms_client_id', $pmsClientId)->first()
            : null;

        $practice = $client
            ? AdvancedMdPractice::query()
                ->where('pms_client_id', $client->pms_client_id)
                ->where('is_active', true)
                ->first()
            : null;

        return view('inbound::advancedmd.page', [
            'client'   => $client,
            'practice' => $practice,
            'success'  => $request->query('success'),
            'error'    => $request->query('error'),
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pms_client_id' => ['required', 'string'],
            'office_key'    => ['required', 'string', 'max:20'],
            'app_name'      => ['required', 'string', 'max:50'],
            'username'      => ['required', 'string', 'max:100'],
            'password'      => ['nullable', 'string', 'max:255'],
        ]);

        $client = Client::query()
            ->where('pms_client_id', $validated['pms_client_id'])
            ->firstOrFail();

        $existing = AdvancedMdPractice::query()
            ->where('pms_client_id', $client->pms_client_id)
            ->where('office_key', $validated['office_key'])
            ->first();

        $updateData = [
            'app_name'           => strtoupper($validated['app_name']),
            'username_encrypted' => $validated['username'],
            'session_token'      => null,
            'session_expires_at' => null,
            'is_active'          => true,
            'last_error'         => null,
        ];

        if (filled($validated['password'])) {
            $updateData['password_encrypted'] = $validated['password'];
        } elseif (! $existing) {
            return redirect()->route('inbound.advancedmd.page', [
                'pms_client_id' => $validated['pms_client_id'],
                'error'         => 'Password is required for a new connection.',
            ]);
        }

        $practice = AdvancedMdPractice::query()->updateOrCreate(
            [
                'pms_client_id' => $client->pms_client_id,
                'office_key'    => $validated['office_key'],
            ],
            $updateData
        );

        try {
            $this->session->login($practice);

            return redirect()->route('inbound.advancedmd.page', [
                'pms_client_id' => $client->pms_client_id,
                'success'       => 'AdvancedMD connected successfully.',
            ]);
        } catch (\Throwable $e) {
            return redirect()->route('inbound.advancedmd.page', [
                'pms_client_id' => $client->pms_client_id,
                'error'         => 'Connection failed: ' . $e->getMessage(),
            ]);
        }
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $pmsClientId = (string) $request->input('pms_client_id', '');

        AdvancedMdPractice::query()
            ->where('pms_client_id', $pmsClientId)
            ->update(['is_active' => false]);

        return redirect()->route('inbound.advancedmd.page', [
            'pms_client_id' => $pmsClientId,
            'success'       => 'AdvancedMD disconnected.',
        ]);
    }
}
