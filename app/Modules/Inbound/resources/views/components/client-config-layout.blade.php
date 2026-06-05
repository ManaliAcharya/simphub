{{--
  Client Configuration Layout Shell
  Props:
    $client         — Client model
    $providerLabel  — e.g. "QuickBooks", "Clio", "Custom CRM"
    $tabs           — array of ['id' => 'connection', 'label' => 'PMS Connection']
    $activeTab      — default active tab id (first tab if not set)
--}}
@props([
    'client',
    'providerLabel' => 'Configuration',
    'tabs'          => [],
    'activeTab'     => null,
])

@php
    $defaultTab = $activeTab ?? ($tabs[0]['id'] ?? 'tab0');
@endphp

<style>
/* ── Config layout vars ── */
:root {
    --cc-primary:       #6c63ff;
    --cc-primary-hover: #5a52e0;
    --cc-primary-light: #f0eeff;
    --cc-primary-border:#c5c0ff;
    --cc-bg:            #f5f6fa;
    --cc-card:          #ffffff;
    --cc-text:          #1a1a2e;
    --cc-text-2:        #6b7280;
    --cc-text-3:        #9ca3af;
    --cc-border:        #e5e7eb;
    --cc-border-light:  #f0f0f5;
    --cc-success:       #10b981;
    --cc-success-bg:    #ecfdf5;
    --cc-success-text:  #065f46;
    --cc-r-sm: 6px;
    --cc-r-md: 8px;
    --cc-r-lg: 12px;
    --cc-shadow: 0 1px 2px rgba(0,0,0,0.04);
}
.cc-wrap * { box-sizing: border-box; }

/* ── Top nav ── */
.cc-topnav {
    background:#1a1a2e; padding:0 32px; height:52px;
    display:flex; align-items:center; justify-content:space-between;
    position:sticky; top:0; z-index:100;
}
.cc-topnav-logo { font-size:16px; font-weight:700; color:#fff; letter-spacing:0.3px; text-decoration:none; }
.cc-topnav-logo span { color:var(--cc-primary); }
.cc-breadcrumb { font-size:13px; color:#9ca3af; }
.cc-breadcrumb a { color:#9ca3af; text-decoration:none; transition:color .15s; }
.cc-breadcrumb a:hover { color:#fff; }
.cc-breadcrumb .sep { margin:0 6px; }

/* ── Page wrapper ── */
.cc-page { max-width:920px; margin:0 auto; padding:24px 20px 48px; }

/* ── Client header card ── */
.cc-client-header {
    background:var(--cc-card); border:1px solid var(--cc-border);
    border-radius:var(--cc-r-lg); padding:18px 24px; margin-bottom:18px;
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;
    box-shadow:var(--cc-shadow);
}
.cc-client-name { font-size:19px; font-weight:700; color:var(--cc-text); margin:0 0 3px; }
.cc-client-id {
    font-size:11.5px; color:var(--cc-text-3);
    font-family:ui-monospace,'Fira Code','Consolas',monospace; margin-bottom:4px;
}
.cc-client-meta { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.cc-pms-badge {
    font-size:11px; font-weight:700; padding:2px 10px; border-radius:999px;
    background:#f3f4f6; color:var(--cc-text-2); letter-spacing:0.2px;
}
.cc-active-badge {
    font-size:11px; font-weight:700; padding:3px 12px; border-radius:999px;
    background:var(--cc-success-bg); color:var(--cc-success-text);
}

/* ── Tabs ── */
.cc-tabs {
    display:flex; background:var(--cc-card); border:1px solid var(--cc-border);
    border-radius:var(--cc-r-lg); overflow:hidden; margin-bottom:20px;
    box-shadow:var(--cc-shadow); overflow-x:auto;
}
.cc-tab {
    flex:1; padding:11px 16px; text-align:center; font-size:13px; font-weight:600;
    color:var(--cc-text-2); cursor:pointer; border-bottom:2.5px solid transparent;
    transition:all .15s; white-space:nowrap; user-select:none; border:none;
    background:none; font-family:inherit;
}
.cc-tab:hover:not(.active) { color:var(--cc-primary); background:var(--cc-primary-light); }
.cc-tab.active { color:var(--cc-primary); border-bottom:2.5px solid var(--cc-primary); background:var(--cc-primary-light); }

/* ── Tab panels ── */
.cc-tab-panel { display:none; }
.cc-tab-panel.active { display:block; }

/* ── Cards ── */
.cc-card { background:var(--cc-card); border:1px solid var(--cc-border); border-radius:var(--cc-r-lg); padding:20px 24px; margin-bottom:14px; box-shadow:var(--cc-shadow); }
.cc-card-title { font-size:15px; font-weight:700; color:var(--cc-text); margin:0 0 3px; }
.cc-card-desc  { font-size:13px; color:var(--cc-text-2); margin:0 0 14px; line-height:1.55; }
.cc-card-divider { border:none; border-top:1px solid var(--cc-border-light); margin:16px 0; }

/* ── Connected service badges ── */
.cc-conn-badge { display:inline-flex; align-items:center; gap:6px; background:var(--cc-info-bg,#eff6ff); border:1px solid var(--cc-info-border,#bfdbfe); padding:5px 14px; border-radius:var(--cc-r-md); font-size:13px; font-weight:600; color:var(--cc-info-text,#1e40af); }
.cc-conn-dot { width:7px; height:7px; background:var(--cc-success); border-radius:50%; flex-shrink:0; }

/* ── Inline form fields ── */
.cc-field { margin-bottom:12px; }
.cc-field label { display:block; font-size:13px; font-weight:500; color:var(--cc-text-2); margin-bottom:4px; }
.cc-field select, .cc-field input[type="text"], .cc-field input[type="url"] {
    width:100%; padding:8px 12px; border:1px solid var(--cc-border); border-radius:var(--cc-r-sm);
    font-size:14px; color:var(--cc-text); background:#fafafa; font-family:inherit;
    transition:border-color .15s,box-shadow .15s;
}
.cc-field select:focus, .cc-field input:focus { outline:none; border-color:var(--cc-primary); box-shadow:0 0 0 3px rgba(108,99,255,.08); background:#fff; }

/* ── Copy row ── */
.cc-copy-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.cc-copy-val { font-family:ui-monospace,'Fira Code',monospace; font-size:12px; background:#f3f4f6; border:1px solid var(--cc-border); border-radius:var(--cc-r-sm); padding:5px 10px; color:var(--cc-text); word-break:break-all; }
.cc-copy-btn { padding:4px 12px; background:var(--cc-primary); color:#fff; border:none; border-radius:var(--cc-r-sm); font-size:12px; font-weight:600; cursor:pointer; transition:background .15s; font-family:inherit; }
.cc-copy-btn:hover { background:var(--cc-primary-hover); }
.cc-copy-btn.copied { background:var(--cc-success); }

/* ── Success/error notices ── */
.cc-notice {
    border-radius:var(--cc-r-md); padding:10px 14px; margin-bottom:14px; font-size:13px;
}
.cc-notice.success { background:var(--cc-success-bg); color:var(--cc-success-text); border:1px solid #a7f3d0; }
.cc-notice.error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }

@media(max-width:640px) {
    .cc-topnav { padding:0 16px; }
    .cc-page { padding:16px 12px 32px; }
    .cc-client-header { padding:14px 16px; }
    .cc-tab { padding:10px 12px; font-size:12px; }
}
</style>

<div class="cc-wrap">

    {{-- ── Top nav ── --}}
    <nav class="cc-topnav">
        <a class="cc-topnav-logo" href="{{ route('inbound.clients.create') }}">
            Payment<span>Middleware</span>
        </a>
        <div class="cc-breadcrumb">
            <a href="{{ route('inbound.clients.create') }}">Clients</a>
            <span class="sep">/</span>
            <span>{{ $client->client_name }}</span>
            <span class="sep">/</span>
            <span>Configuration</span>
        </div>
    </nav>

    <div class="cc-page">

        {{-- ── Client header card ── --}}
        <div class="cc-client-header">
            <div>
                <h1 class="cc-client-name">{{ $client->client_name }}</h1>
                <div class="cc-client-id">pms_client_id: {{ $client->pms_client_id }}</div>
                <div class="cc-client-meta">
                    <span class="cc-pms-badge">{{ $client->client_pms }}</span>
                    <span class="cc-pms-badge">{{ $providerLabel }}</span>
                </div>
            </div>
            <span class="cc-active-badge">✓ Active</span>
        </div>

        {{-- ── Success / error flash ── --}}
        @if(session('success') || (isset($success) && $success))
            <div class="cc-notice success">{{ session('success') ?? $success }}</div>
        @endif
        @if(session('error') || (isset($error) && $error))
            <div class="cc-notice error">{{ session('error') ?? $error }}</div>
        @endif

        {{-- ── Tab bar ── --}}
        <div class="cc-tabs" role="tablist">
            @foreach($tabs as $tab)
                <button class="cc-tab {{ $loop->first ? 'active' : '' }}"
                        role="tab"
                        data-tab="{{ $tab['id'] }}"
                        onclick="ccSwitchTab('{{ $tab['id'] }}', this)">
                    {{ $tab['label'] }}
                </button>
            @endforeach
        </div>

        {{-- ── Tab content ── --}}
        {{ $slot }}

    </div>
</div>

<script>
function ccSwitchTab(tabId, btn) {
    // Update tab buttons
    document.querySelectorAll('.cc-tab').forEach(function(t) { t.classList.remove('active'); });
    btn.classList.add('active');
    // Update panels
    document.querySelectorAll('.cc-tab-panel').forEach(function(p) { p.classList.remove('active'); });
    var panel = document.getElementById('cc-panel-' + tabId);
    if (panel) panel.classList.add('active');
}

// Activate the first tab on load
document.addEventListener('DOMContentLoaded', function() {
    var firstBtn = document.querySelector('.cc-tab');
    var firstPanel = document.querySelector('.cc-tab-panel');
    if (firstBtn) firstBtn.classList.add('active');
    if (firstPanel) firstPanel.classList.add('active');
});
</script>
