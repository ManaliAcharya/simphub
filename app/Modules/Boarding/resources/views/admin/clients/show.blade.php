<x-boarding::layouts.master title="{{ $client->name }}">
<style>
    body { font-family:-apple-system,Segoe UI,Roboto,sans-serif; background:#f7f8fa; margin:0; }

    .page-header {
        background:#1a1a2e; padding:20px 32px;
        display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;
    }
    .page-header h1 { color:#fff; font-size:18px; font-weight:700; margin:0; }
    .breadcrumb { font-size:13px; color:rgba(255,255,255,.55); margin-bottom:4px; }
    .breadcrumb a { color:rgba(255,255,255,.75); text-decoration:none; }
    .breadcrumb a:hover { color:#fff; }
    .header-actions { display:flex; gap:10px; }

    .page-body { padding:28px 32px; max-width:1000px; margin:0 auto; }

    .btn-primary {
        display:inline-flex; align-items:center; gap:7px;
        background:#2563eb; color:#fff; border:none; border-radius:9px;
        padding:9px 18px; font:inherit; font-size:13px; font-weight:600;
        cursor:pointer; text-decoration:none; transition:background .15s;
    }
    .btn-primary:hover { background:#1d4ed8; }
    .btn-danger { background:#dc2626; color:#fff; border:none; border-radius:9px; padding:9px 18px; font:inherit; font-size:13px; font-weight:600; cursor:pointer; }
    .btn-danger:hover { background:#b91c1c; }
    .btn-success { background:#16a34a; color:#fff; border:none; border-radius:9px; padding:9px 18px; font:inherit; font-size:13px; font-weight:600; cursor:pointer; }
    .btn-success:hover { background:#15803d; }

    .panel { background:#fff; border:1px solid #e5e7eb; border-radius:12px; margin-bottom:20px; }
    .panel-header { padding:16px 22px; border-bottom:1px solid #f3f4f6; display:flex; align-items:center; justify-content:space-between; }
    .panel-header h2 { font-size:14px; font-weight:700; margin:0; color:#111827; }
    .panel-body { padding:18px 22px; }

    .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    .field { display:flex; flex-direction:column; gap:4px; }
    .field span { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; }
    .field strong, .field code { font-size:.88rem; color:#111827; }
    code { font-family:ui-monospace,'Cascadia Code',monospace; background:#f3f4f6; padding:2px 6px; border-radius:4px; }

    .badge { display:inline-block; padding:2px 10px; border-radius:20px; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
    .badge-active, .badge-clicked { background:#d1fae5; color:#065f46; }
    .badge-inactive { background:#f3f4f6; color:#6b7280; }
    .badge-link_generated { background:#fef3c7; color:#92400e; }
    .badge-revoked { background:#fee2e2; color:#991b1b; }

    table { width:100%; border-collapse:collapse; }
    th { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#9ca3af; text-align:left; padding:9px 22px; border-bottom:1px solid #f3f4f6; }
    td { padding:11px 22px; border-bottom:1px solid #f9fafb; font-size:.85rem; vertical-align:middle; }
    tr:last-child td { border-bottom:none; }

    .key-row { display:flex; align-items:center; gap:8px; }
    .key-box { flex:1; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:8px; padding:8px 12px; font-family:ui-monospace,monospace; font-size:.85rem; word-break:break-all; color:#111827; }
    .icon-btn { flex-shrink:0; padding:7px 10px; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:8px; cursor:pointer; }
    .copy-btn { flex-shrink:0; padding:7px 16px; background:#2563eb; color:#fff; border:none; border-radius:8px; font-size:.82rem; font-weight:600; cursor:pointer; font-family:inherit; }

    .pw-form { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
    .pw-form input { flex:1; min-width:200px; padding:8px 12px; border:1.5px solid #e5e7eb; border-radius:8px; font-size:.88rem; font-family:inherit; }
</style>

<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="{{ route('inbound.clients.index') }}">Clients</a> / {{ $client->name }}</div>
        <h1>{{ $client->name }}</h1>
    </div>
    <div class="header-actions">
        <a href="{{ route('inbound.clients.boarding.api-docs', $client->client_id) }}" class="btn-primary">API Docs</a>
    </div>
</div>

<div class="page-body">

    @if(session('success'))
        <div style="padding:12px 16px;background:#ecfdf5;border:1px solid #6ee7b7;border-radius:10px;font-size:13px;color:#065f46;margin-bottom:16px;">
            {{ session('success') }}
        </div>
    @endif

    <div class="panel">
        <div class="panel-header"><h2>Overview</h2></div>
        <div class="panel-body">
            <div class="grid2" style="margin-bottom:14px;">
                <div class="field">
                    <span>Client ID</span>
                    <code>{{ $client->client_id }}</code>
                </div>
                <div class="field">
                    <span>Status</span>
                    <strong><span class="badge badge-{{ $client->status }}">{{ $client->status }}</span></strong>
                </div>
                <div class="field">
                    <span>Contact Email</span>
                    <strong>{{ $client->contact_email }}</strong>
                </div>
                <div class="field">
                    <span>Created</span>
                    <strong>{{ $client->created_at->format('M d, Y') }}</strong>
                </div>
                <div class="field">
                    <span>Portal Login</span>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <code style="font-size:.78rem;">{{ url('/login') }}</code>
                        @if($client->account?->password_hash)
                            <span style="font-size:.72rem;color:#065f46;background:#d1fae5;padding:2px 8px;border-radius:12px;font-weight:700;">Password set</span>
                        @else
                            <span style="font-size:.72rem;color:#92400e;background:#fef3c7;padding:2px 8px;border-radius:12px;font-weight:700;">Invitation pending</span>
                        @endif
                    </div>
                </div>
                <div class="field">
                    <span>Webhook URL (ISOHub)</span>
                    <strong>{{ $client->webhook_url ?? '(not set)' }}</strong>
                </div>
            </div>
            <div class="field">
                <span>API Key</span>
                <div class="key-row" style="margin-top:4px;">
                    <div id="api-key-display" class="key-box">{{ str_repeat('•', 52) }}</div>
                    <button type="button" class="icon-btn" onclick="toggleApiKey()">Show</button>
                    <button type="button" class="copy-btn" onclick="copyApiKey(this)">Copy</button>
                </div>
            </div>
        </div>
    </div>
    <script>
        const RAW_KEY = @json($apiKey);
        let apiKeyVisible = false;
        function toggleApiKey() {
            apiKeyVisible = !apiKeyVisible;
            document.getElementById('api-key-display').textContent = apiKeyVisible ? RAW_KEY : '•'.repeat(52);
        }
        function copyApiKey(btn) {
            navigator.clipboard.writeText(RAW_KEY).then(() => {
                btn.textContent = 'Copied!';
                setTimeout(() => { btn.textContent = 'Copy'; }, 2000);
            });
        }
    </script>

    <div class="panel">
        <div class="panel-header"><h2>Master Square Links</h2></div>
        @if($client->masterLinks->isEmpty())
            <div class="panel-body"><p style="color:#6b7280;margin:0;font-size:.85rem;">Not configured yet — the ISO sets these on their portal, or you can set them on their behalf there.</p></div>
        @else
            <table>
                <thead><tr><th>Tier</th><th>Processor</th><th>URL</th></tr></thead>
                <tbody>
                    @foreach($client->masterLinks as $link)
                    <tr>
                        <td>{{ str_replace('_', ' ', $link->tier) }}</td>
                        <td>{{ $link->processor }}</td>
                        <td><code style="font-size:.78rem;word-break:break-all;">{{ $link->url }}</code></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="panel">
        <div class="panel-header"><h2>Merchants ({{ $client->merchants->count() }})</h2></div>
        @if($client->merchants->isEmpty())
            <div class="panel-body"><p style="color:#6b7280;margin:0;font-size:.85rem;">No boarding links generated yet.</p></div>
        @else
            <table>
                <thead>
                    <tr><th>Name</th><th>Ref</th><th>Agent</th><th>Tier</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @foreach($client->merchants as $merchant)
                    <tr>
                        <td><strong>{{ $merchant->merchant_name }}</strong></td>
                        <td><code style="font-size:.78rem;">{{ $merchant->merchant_ref }}</code></td>
                        <td style="color:#6b7280;">{{ $merchant->agent_ref }}</td>
                        <td>{{ str_replace('_', ' ', $merchant->tier) }}</td>
                        <td><span class="badge badge-{{ $merchant->status }}">{{ str_replace('_', ' ', $merchant->status) }}</span></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="panel">
        <div class="panel-header"><h2>Portal Access</h2></div>
        <div class="panel-body">
            <p style="color:#6b7280;margin:0 0 12px;font-size:.85rem;">
                {{ $client->account?->password_hash ? 'The client already has a password set. Resending will let them set a new one.' : 'The client hasn\'t set a password yet. Resend the invitation email if they lost it.' }}
            </p>
            <form method="POST" action="{{ route('inbound.clients.boarding.resend-invitation', $client->client_id) }}">
                @csrf
                <button type="submit" class="btn-primary" style="border:none;">Resend Invitation Email</button>
            </form>
        </div>
    </div>

    <div class="actions" style="margin-bottom:24px;">
        <form method="POST" action="{{ route('inbound.clients.boarding.toggle-status', $client->client_id) }}">
            @csrf
            <button type="submit" class="{{ $client->status === 'active' ? 'btn-danger' : 'btn-success' }}">
                {{ $client->status === 'active' ? 'Deactivate Client' : 'Activate Client' }}
            </button>
        </form>
    </div>

</div>
</x-boarding::layouts.master>
