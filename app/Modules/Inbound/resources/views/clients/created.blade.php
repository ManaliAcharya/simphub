<x-inbound::layouts.master>
<style>
    body { font-family:-apple-system,Segoe UI,Roboto,sans-serif; background:#f7f8fa; margin:0; }
    .page-wrap { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:32px 16px; }
    .card {
        background:#fff; border:1px solid #e5e7eb; border-radius:16px;
        box-shadow:0 4px 24px rgba(0,0,0,.07); padding:48px 40px;
        max-width:520px; width:100%; text-align:center;
    }
    .check-circle {
        width:64px; height:64px; border-radius:50%; background:#d1fae5;
        display:flex; align-items:center; justify-content:center; margin:0 auto 24px;
    }
    .check-circle svg { width:32px; height:32px; color:#059669; }
    h1 { font-size:22px; font-weight:700; color:#111827; margin:0 0 8px; }
    .subtitle { font-size:14px; color:#6b7280; margin:0 0 32px; line-height:1.6; }
    .client-name { font-weight:600; color:#111827; }

    .link-box {
        background:#f0fdf4; border:1.5px solid #86efac; border-radius:10px;
        padding:16px 20px; text-align:left; margin-bottom:28px;
    }
    .link-label { font-size:11px; font-weight:700; text-transform:uppercase;
        letter-spacing:.07em; color:#15803d; margin-bottom:8px; }
    .link-row { display:flex; gap:8px; align-items:center; }
    .link-input {
        flex:1; padding:9px 12px; border:1px solid #bbf7d0; border-radius:8px;
        font:inherit; font-size:13px; background:#fff; color:#111827;
        white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    }
    .copy-btn {
        padding:9px 14px; background:#16a34a; color:#fff; border:none;
        border-radius:8px; font:inherit; font-size:13px; font-weight:600;
        cursor:pointer; white-space:nowrap; transition:background .15s;
    }
    .copy-btn:hover { background:#15803d; }
    .copy-btn.copied { background:#059669; }

    .hint { font-size:12px; color:#6b7280; margin-top:8px; line-height:1.5; }

    .actions { display:flex; gap:12px; justify-content:center; flex-wrap:wrap; }
    .btn-primary {
        padding:10px 22px; background:#2563eb; color:#fff; border:none;
        border-radius:9px; font:inherit; font-size:14px; font-weight:600;
        cursor:pointer; text-decoration:none; transition:background .15s;
    }
    .btn-primary:hover { background:#1d4ed8; }
    .btn-secondary {
        padding:10px 22px; background:#fff; color:#374151; border:1.5px solid #d1d5db;
        border-radius:9px; font:inherit; font-size:14px; font-weight:500;
        cursor:pointer; text-decoration:none; transition:background .15s;
    }
    .btn-secondary:hover { background:#f9fafb; }
</style>

<div class="page-wrap">
    <div class="card">
        <div class="check-circle">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
            </svg>
        </div>

        <h1>Client created successfully</h1>
        <p class="subtitle">
            <span class="client-name">{{ $client->client_name }}</span> has been set up.
            Share the link below so they can connect their
            {{ ucfirst($provider === 'quickbooks' ? 'QuickBooks' : ucwords(str_replace('_', ' ', $provider))) }}
            account and complete onboarding.
        </p>

        @if($shareUrl)
        <div class="link-box">
            <div class="link-label">Setup link to share with client</div>
            <div class="link-row">
                <input id="share-url" class="link-input" type="text" value="{{ $shareUrl }}" readonly>
                <button class="copy-btn" id="copy-btn" onclick="copyLink()">Copy</button>
            </div>
            <p class="hint">Send this link to the client. They will use it to connect their PMS account.</p>
        </div>
        @endif

        <div class="actions">
            @php
                $configUrl = match($provider) {
                    'custom'      => route('inbound.clients.api-docs',  ['pms_client_id' => $client->pms_client_id]),
                    'quickbooks'  => route('inbound.quickbooks.page',   ['pms_client_id' => $client->pms_client_id]),
                    'zoho'        => route('inbound.zoho.page',         ['pms_client_id' => $client->pms_client_id]),
                    'wave'        => route('inbound.wave.page',         ['pms_client_id' => $client->pms_client_id]),
                    'lawcus'      => route('inbound.lawcus.page',       ['pms_client_id' => $client->pms_client_id]),
                    'mindbody'    => route('inbound.mindbody.page',     ['pms_client_id' => $client->pms_client_id]),
                    default       => route('inbound.clio.page',         ['pms_client_id' => $client->pms_client_id]),
                };
            @endphp
            <a href="{{ $configUrl }}" class="btn-primary">Go to client config</a>
            <a href="{{ route('inbound.clients.create') }}" class="btn-secondary">Create another client</a>
        </div>
    </div>
</div>

<script>
function copyLink() {
    const input = document.getElementById('share-url');
    const btn   = document.getElementById('copy-btn');
    navigator.clipboard.writeText(input.value).then(function() {
        btn.textContent = 'Copied!';
        btn.classList.add('copied');
        setTimeout(function() {
            btn.textContent = 'Copy';
            btn.classList.remove('copied');
        }, 2000);
    }).catch(function() {
        input.select();
        document.execCommand('copy');
    });
}
</script>
</x-inbound::layouts.master>
