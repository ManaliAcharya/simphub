<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BookSync — {{ $client->name }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f1f5f9; color: #111827; min-height: 100vh; }
        .topbar { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 0 32px; height: 56px; display: flex; align-items: center; justify-content: space-between; }
        .topbar-logo { font-size: 1.15rem; font-weight: 800; color: #111827; letter-spacing: -.02em; }
        .topbar-logo span { color: #2563eb; }
        .topbar-right { display: flex; align-items: center; gap: 16px; }
        .topbar-client { font-size: .85rem; color: #6b7280; }
        .topbar-client strong { color: #111827; }
        .logout-btn { font-size: .82rem; color: #6b7280; border: 1px solid #e5e7eb; background: #fff; padding: 5px 14px; border-radius: 6px; cursor: pointer; font-family: inherit; }
        .logout-btn:hover { background: #f9fafb; }
        .main { max-width: 900px; margin: 0 auto; padding: 32px 20px; }
        h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: 4px; }
        .eyebrow { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #6b7280; margin-bottom: 6px; }
        .copy { font-size: .88rem; color: #6b7280; margin-bottom: 24px; }
        .panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; margin-bottom: 24px; }
        .panel-header { padding: 18px 22px 14px; border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; justify-content: space-between; }
        .panel-header h2 { font-size: 1rem; font-weight: 700; }
        .panel-body { padding: 18px 22px; }
        .field { display: flex; flex-direction: column; gap: 4px; }
        .field span { font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: #9ca3af; }
        .field strong, .field code { font-size: .9rem; color: #111827; }
        code { font-family: ui-monospace, 'Cascadia Code', monospace; background: #f3f4f6; padding: 2px 6px; border-radius: 4px; }
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
        .badge-active { background: #d1fae5; color: #065f46; }
        .badge-inactive { background: #f3f4f6; color: #6b7280; }
        .badge-pending_qb_connect { background: #fef3c7; color: #92400e; }
        .badge-qb_token_expired { background: #fee2e2; color: #991b1b; }
        .badge-disabled { background: #f3f4f6; color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        th { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #9ca3af; text-align: left; padding: 10px 14px; border-bottom: 1px solid #f3f4f6; }
        td { padding: 12px 14px; border-bottom: 1px solid #f9fafb; font-size: .88rem; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        .btn-sm { display: inline-block; padding: 5px 14px; border-radius: 6px; font-size: .78rem; font-weight: 600; text-decoration: none; border: 1px solid #e5e7eb; background: #f9fafb; color: #374151; cursor: pointer; }
        .btn-sm:hover { background: #f3f4f6; }
        .btn-primary { background: #2563eb; color: #fff; border-color: #2563eb; }
        .btn-primary:hover { background: #1d4ed8; }
        .key-row { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
        .key-box { flex: 1; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 12px; font-family: ui-monospace, monospace; font-size: .85rem; word-break: break-all; letter-spacing: .04em; color: #111827; }
        .icon-btn { flex-shrink: 0; padding: 7px 10px; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 8px; cursor: pointer; line-height: 1; }
        .copy-btn { flex-shrink: 0; padding: 7px 16px; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-size: .82rem; font-weight: 600; cursor: pointer; font-family: inherit; }
    </style>
</head>
<body>

<div class="topbar">
    <div class="topbar-logo">Book<span>Sync</span></div>
    <div class="topbar-right">
        <div class="topbar-client">Signed in as <strong>{{ $client->name }}</strong></div>
        <form method="POST" action="{{ route('booksync.portal.logout') }}" style="margin:0;">
            @csrf
            <button type="submit" class="logout-btn">Sign out</button>
        </form>
    </div>
</div>

<div class="main">

    <p class="eyebrow">Client Portal</p>
    <h1>{{ $client->name }}</h1>
    <p class="copy">Your BookSync integration overview. Use the API key below to authenticate your requests.</p>

    {{-- API credentials --}}
    <div class="panel">
        <div class="panel-header"><h2>API Credentials</h2></div>
        <div class="panel-body">
            <div class="grid2" style="margin-bottom:16px;">
                <div class="field">
                    <span>Client ID</span>
                    <code>{{ $client->client_id }}</code>
                </div>
                <div class="field">
                    <span>Status</span>
                    <strong><span class="badge badge-{{ $client->status }}">{{ $client->status }}</span></strong>
                </div>
            </div>
            <div class="field">
                <span>API Key</span>
                <div class="key-row">
                    <div id="api-key-box" class="key-box">{{ str_repeat('•', 52) }}</div>
                    <button type="button" class="icon-btn" onclick="toggleKey()" title="Show / hide">
                        <svg id="eye-show" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg id="eye-hide" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                    <button type="button" class="copy-btn" onclick="copyKey(this)">Copy</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Merchants --}}
    <div class="panel">
        <div class="panel-header">
            <h2>Merchants ({{ $client->merchants->count() }})</h2>
            <a href="{{ route('booksync.docs') }}" class="btn-sm btn-primary" target="_blank">API Docs</a>
        </div>
        @if($client->merchants->isEmpty())
            <div class="panel-body">
                <p style="color:#9ca3af;font-size:.88rem;">No merchants yet. Contact your BookSync admin to add a merchant and receive a setup link.</p>
            </div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Merchant Token</th>
                        <th>QB Company</th>
                        <th>QB Status</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($client->merchants as $merchant)
                    <tr>
                        <td><strong>{{ $merchant->name }}</strong></td>
                        <td><code style="font-size:.78rem;">{{ $merchant->posting_token }}</code></td>
                        <td style="color:#6b7280;">{{ $merchant->qb_company_name ?? '—' }}</td>
                        <td>
                            @if($merchant->status === 'active')
                                <span style="color:#065f46;font-weight:600;font-size:.82rem;">Connected</span>
                            @elseif($merchant->status === 'pending_qb_connect')
                                <span style="color:#92400e;font-size:.82rem;">Pending QB setup</span>
                            @elseif($merchant->status === 'qb_token_expired')
                                <span style="color:#991b1b;font-size:.82rem;">Token expired</span>
                            @else
                                <span style="color:#6b7280;font-size:.82rem;">{{ str_replace('_', ' ', $merchant->status) }}</span>
                            @endif
                        </td>
                        <td><span class="badge badge-{{ $merchant->status }}">{{ $merchant->status === 'active' ? 'active' : 'inactive' }}</span></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

</div>

<script>
const RAW_KEY = @json($apiKey);
let visible = false;
function toggleKey() {
    visible = !visible;
    document.getElementById('api-key-box').textContent = visible ? RAW_KEY : '•'.repeat(52);
    document.getElementById('eye-show').style.display = visible ? 'none' : '';
    document.getElementById('eye-hide').style.display = visible ? '' : 'none';
}
function copyKey(btn) {
    navigator.clipboard.writeText(RAW_KEY).then(() => {
        btn.textContent = 'Copied!'; btn.style.background = '#10b981';
        setTimeout(() => { btn.textContent = 'Copy'; btn.style.background = '#2563eb'; }, 2000);
    });
}
</script>

</body>
</html>
