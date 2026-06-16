<?php

namespace Modules\BookSync\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\BookSync\Models\BookSyncClient;
use Modules\BookSync\Services\AccountingConnectorRegistry;

class ClientController extends Controller
{
    public function __construct(
        private readonly AccountingConnectorRegistry $connectors,
    ) {}

    public function index()
    {
        $clients = BookSyncClient::withCount('merchants')->latest()->get();

        return view('booksync::admin.clients.index', compact('clients'));
    }

    public function create()
    {
        return view('booksync::admin.clients.create', [
            'connectors' => $this->connectors->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'               => ['required', 'string', 'max:255'],
            'contact_email'      => ['nullable', 'email', 'max:255'],
            'accounting_system'  => ['required', 'string', 'in:' . implode(',', $this->connectors->keys())],
        ]);

        $clientId  = 'cl_' . Str::uuid()->toString();
        $rawApiKey = 'bsk_' . Str::random(48);

        $client = BookSyncClient::create([
            'name'              => $data['name'],
            'contact_email'     => $data['contact_email'] ?? null,
            'accounting_system' => $data['accounting_system'],
            'client_id'         => $clientId,
            'client_api_key'    => $rawApiKey,
            'status'            => 'active',
        ]);

        return redirect()
            ->route('booksync.admin.clients.show', $client->client_id);
    }

    public function show(string $clientId)
    {
        $client = BookSyncClient::where('client_id', $clientId)
            ->with('merchants')
            ->firstOrFail();

        $accountingSystem = $client->accounting_system ?: 'quickbooks';

        return view('booksync::admin.clients.show', [
            'client'    => $client,
            'apiKey'    => $client->client_api_key,
            'connector' => $this->connectors->for($accountingSystem),
        ]);
    }

    public function toggleStatus(string $clientId): RedirectResponse
    {
        $client = BookSyncClient::where('client_id', $clientId)->firstOrFail();
        $client->update(['status' => $client->status === 'active' ? 'inactive' : 'active']);

        return back()->with('success', 'Client status updated.');
    }
}
