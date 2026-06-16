<x-booksync::layouts.master title="{{ $client->name }}">

<div class="nav">
    <a href="{{ route('booksync.admin.clients.index') }}">Clients</a>
    <span class="sep">›</span>
    <span>{{ $client->name }}</span>
</div>

@if(session('api_key_plaintext'))
<div class="alert alert-warning" style="font-size:.92rem;">
    <strong>Save this API key now — it will not be shown again.</strong><br>
    <code style="background:rgba(0,0,0,.08);padding:4px 8px;border-radius:8px;display:inline-block;margin-top:6px;word-break:break-all;">{{ session('api_key_plaintext') }}</code>
</div>
@endif

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<p class="eyebrow">BookSync — Client</p>
<h1>{{ $client->name }}</h1>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px;">
    <div class="field">
        <span>Client ID</span>
        <code>{{ $client->client_id }}</code>
    </div>
    <div class="field">
        <span>Status</span>
        <strong><span class="badge badge-{{ $client->status }}">{{ $client->status }}</span></strong>
    </div>
    @if($client->contact_email)
    <div class="field">
        <span>Contact Email</span>
        <strong>{{ $client->contact_email }}</strong>
    </div>
    @endif
    <div class="field">
        <span>Created</span>
        <strong>{{ $client->created_at->format('M d, Y') }}</strong>
    </div>
</div>

<div class="panel">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
        <h2 style="margin:0;">Merchants ({{ $client->merchants->count() }})</h2>
        <a href="{{ route('booksync.admin.merchants.create', $client->client_id) }}" class="button btn-primary" style="padding:8px 18px;font-size:.85rem;">+ Add Merchant</a>
    </div>

    @if($client->merchants->isEmpty())
        <p style="color:#6b7c93;margin:0;">No merchants yet. Add the first merchant to generate a setup link.</p>
    @else
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Merchant ID</th>
                    <th>QB Status</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($client->merchants as $merchant)
                <tr>
                    <td><strong>{{ $merchant->name }}</strong></td>
                    <td><code style="font-size:.82rem;">{{ $merchant->merchant_id }}</code></td>
                    <td>{{ $merchant->qb_company_name ?? '—' }}</td>
                    <td><span class="badge badge-{{ $merchant->status }}">{{ str_replace('_', ' ', $merchant->status) }}</span></td>
                    <td><a href="{{ route('booksync.admin.merchants.show', $merchant->merchant_id) }}" class="button btn-secondary" style="padding:6px 14px;font-size:.82rem;">View</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="actions">
    <a href="{{ route('booksync.admin.clients.api-docs', $client->client_id) }}" class="button btn-primary">API Docs</a>
    <form method="POST" action="{{ route('booksync.admin.clients.toggle-status', $client->client_id) }}">
        @csrf
        <button type="submit" class="button {{ $client->status === 'active' ? 'btn-danger' : 'btn-success' }}">
            {{ $client->status === 'active' ? 'Deactivate Client' : 'Activate Client' }}
        </button>
    </form>
</div>

</x-booksync::layouts.master>
