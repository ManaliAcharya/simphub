<x-booksync::layouts.master title="{{ $client->name }}">

<div class="nav">
    <a href="{{ route('booksync.admin.clients.index') }}">Clients</a>
    <span class="sep">›</span>
    <span>{{ $client->name }}</span>
</div>

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
        <span>Accounting System</span>
        <strong>{{ $connector->label() }}</strong>
    </div>
    <div class="field">
        <span>Created</span>
        <strong>{{ $client->created_at->format('M d, Y') }}</strong>
    </div>
    <div class="field" style="grid-column:1/-1;">
        <span>API Key</span>
        <div style="display:flex;align-items:center;gap:8px;margin-top:4px;">
            <code id="api-key-display" style="flex:1;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:7px 12px;font-size:.85rem;word-break:break-all;letter-spacing:.05em;">{{ str_repeat('•', 52) }}</code>
            <button type="button" onclick="toggleApiKey()" title="Show / hide"
                style="flex-shrink:0;padding:6px 10px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;line-height:1;">
                <svg id="eye-show" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg id="eye-hide" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
            <button type="button" onclick="copyApiKey(this)"
                style="flex-shrink:0;padding:6px 14px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;font-family:inherit;">
                Copy
            </button>
        </div>
    </div>
</div>
<script>
    const RAW_KEY = @json($apiKey);
    let visible = false;
    function toggleApiKey() {
        visible = !visible;
        document.getElementById('api-key-display').textContent = visible ? RAW_KEY : '•'.repeat(52);
        document.getElementById('eye-show').style.display = visible ? 'none' : '';
        document.getElementById('eye-hide').style.display = visible ? '' : 'none';
    }
    function copyApiKey(btn) {
        navigator.clipboard.writeText(RAW_KEY).then(() => {
            btn.textContent = 'Copied!';
            btn.style.background = '#10b981';
            setTimeout(() => { btn.textContent = 'Copy'; btn.style.background = '#2563eb'; }, 2000);
        });
    }
</script>

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
