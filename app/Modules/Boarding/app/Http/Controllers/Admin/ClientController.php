<?php

namespace Modules\Boarding\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Modules\Auth\Services\InvitationService;
use Modules\Boarding\Models\BoardingClient;

class ClientController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitationService
    ) {}

    public function show(string $clientId)
    {
        $client = $this->findVisible($clientId, ['merchants', 'masterLinks', 'account']);

        return view('boarding::admin.clients.show', [
            'client' => $client,
            'apiKey' => $client->client_api_key,
        ]);
    }

    public function apiDocs(string $clientId)
    {
        // Also linked from the client's own portal (no admin login), so it
        // stays reachable without one; a logged-in agent is still limited
        // to their own clients.
        $client = auth()->check()
            ? $this->findVisible($clientId)
            : BoardingClient::where('client_id', $clientId)->firstOrFail();

        return view('boarding::admin.clients.api-docs', [
            'client'  => $client,
            'apiKey'  => $client->client_api_key,
            'baseUrl' => rtrim(config('app.url'), '/'),
        ]);
    }

    public function toggleStatus(string $clientId): RedirectResponse
    {
        $client = $this->findVisible($clientId);
        $client->update(['status' => $client->status === 'active' ? 'inactive' : 'active']);

        return back()->with('success', 'Client status updated.');
    }

    public function resendInvitation(string $clientId): RedirectResponse
    {
        $client = $this->findVisible($clientId);

        $account = $client->account;

        if (! $account) {
            $this->invitationService->createInvitation([
                'owner' => $client,
                'email' => $client->contact_email,
                'admin_id' => 0,
            ]);

            return back()->with('success', 'Invitation email sent.');
        }

        $this->invitationService->sendInvitationForExistingAccount([
            'client_account_id' => $account->id,
            'email' => $account->email,
            'admin_id' => 0,
        ]);

        return back()->with('success', 'Invitation email resent.');
    }

    /** Admins can open any boarding client; agents only their own (404 otherwise). */
    private function findVisible(string $clientId, array $with = []): BoardingClient
    {
        return BoardingClient::query()
            ->visibleTo(auth()->user())
            ->where('client_id', $clientId)
            ->with($with)
            ->firstOrFail();
    }
}
