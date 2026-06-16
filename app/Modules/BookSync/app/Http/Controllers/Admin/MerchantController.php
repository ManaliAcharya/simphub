<?php

namespace Modules\BookSync\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\BookSync\Models\BookSyncClient;
use Modules\BookSync\Models\BookSyncMerchant;

class MerchantController extends Controller
{
    public function create(string $clientId)
    {
        $client = BookSyncClient::where('client_id', $clientId)->firstOrFail();

        return view('booksync::admin.merchants.create', compact('client'));
    }

    public function store(Request $request, string $clientId): RedirectResponse
    {
        $client = BookSyncClient::where('client_id', $clientId)->firstOrFail();

        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:255'],
            'merchant_email'       => ['nullable', 'email', 'max:255'],
            'external_merchant_id' => ['nullable', 'string', 'max:100'],
        ]);

        $merchant = BookSyncMerchant::create([
            'client_id'            => $client->id,
            'name'                 => $data['name'],
            'merchant_email'       => $data['merchant_email'] ?? null,
            'external_merchant_id' => $data['external_merchant_id'] ?? null,
            'merchant_id'          => 'm_' . Str::uuid()->toString(),
            'setup_token'          => Str::random(48),
            'status'               => 'pending_qb_connect',
        ]);

        return redirect()->route('booksync.admin.merchants.show', $merchant->merchant_id)
            ->with('success', 'Merchant created. Send the setup link to the merchant.');
    }

    public function show(string $merchantId)
    {
        $merchant = BookSyncMerchant::where('merchant_id', $merchantId)
            ->with('client', 'batches')
            ->firstOrFail();

        return view('booksync::admin.merchants.show', compact('merchant'));
    }

    public function toggleStatus(Request $request, string $merchantId): RedirectResponse
    {
        $merchant = BookSyncMerchant::where('merchant_id', $merchantId)->firstOrFail();
        $merchant->update(['status' => $request->input('status', 'disabled')]);

        return back()->with('success', 'Merchant status updated.');
    }
}
