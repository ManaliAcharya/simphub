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
        $client = BoardingClient::where('client_id', $clientId)
            ->with(['merchants', 'masterLinks', 'account'])
            ->firstOrFail();

        return view('boarding::admin.clients.show', [
            'client' => $client,
            'apiKey' => $client->client_api_key,
        ]);
    }

    public function apiDocs(string $clientId)
    {
        $client = BoardingClient::where('client_id', $clientId)->firstOrFail();

        return view('boarding::admin.clients.api-docs', [
            'client'  => $client,
            'apiKey'  => $client->client_api_key,
            'baseUrl' => rtrim(config('app.url'), '/'),
        ]);
    }

    public function toggleStatus(string $clientId): RedirectResponse
    {
        $client = BoardingClient::where('client_id', $clientId)->firstOrFail();
        $client->update(['status' => $client->status === 'active' ? 'inactive' : 'active']);

        return back()->with('success', 'Client status updated.');
    }

    public function resendInvitation(string $clientId): RedirectResponse
    {
        $client = BoardingClient::where('client_id', $clientId)->firstOrFail();

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
}
