<x-inbound::layouts.master title="PMS Feature Settings">
<style>
    body { font-family:-apple-system,Segoe UI,Roboto,sans-serif; background:#f7f8fa; margin:0; }

    .page-header {
        background:#1a1a2e; padding:20px 32px;
        display:flex; align-items:center; justify-content:space-between; gap:16px;
    }
    .page-header h1 { color:#fff; font-size:18px; font-weight:700; margin:0; }
    .page-header .sub { color:rgba(255,255,255,.55); font-size:13px; margin-top:2px; }
    .page-header a { color:rgba(255,255,255,.6); font-size:13px; text-decoration:none; }
    .page-header a:hover { color:#fff; }

    .page-body { padding:28px 32px; max-width:1100px; margin:0 auto; }

    .alert {
        padding:12px 16px; border-radius:10px; font-size:13px; margin-bottom:20px;
        display:flex; align-items:center; gap:10px;
    }
    .alert-success { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }
    .alert-error   { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }

    /* ── Matrix card ── */
    .matrix-card {
        background:#fff; border:1px solid #e5e7eb; border-radius:14px;
        overflow:hidden; box-shadow:0 1px 6px rgba(0,0,0,.06);
    }
    .matrix-card-header {
        padding:16px 20px; border-bottom:1px solid #f1f5f9;
        display:flex; align-items:center; justify-content:space-between; gap:12px;
    }
    .matrix-card-header h2 { margin:0; font-size:15px; font-weight:700; color:#111827; }
    .matrix-card-header p  { margin:4px 0 0; font-size:13px; color:#6b7280; }

    .matrix-table { width:100%; border-collapse:collapse; }
    .matrix-table th {
        padding:10px 16px; background:#f8fafc;
        font-size:11px; font-weight:700; text-transform:uppercase;
        letter-spacing:.06em; color:#6b7280; border-bottom:1px solid #e5e7eb;
        text-align:center; white-space:nowrap;
    }
    .matrix-table th.feature-col { text-align:left; min-width:260px; }
    .matrix-table td {
        padding:14px 16px; border-bottom:1px solid #f1f5f9;
        vertical-align:middle;
    }
    .matrix-table td.feature-label { text-align:left; }
    .matrix-table td.toggle-cell   { text-align:center; }
    .matrix-table tr:last-child td { border-bottom:none; }
    .matrix-table tr:hover td      { background:#fafbfc; }

    .feature-name { font-size:13px; font-weight:600; color:#111827; }
    .feature-desc { font-size:12px; color:#6b7280; margin-top:2px; }

    /* ── Toggle switch ── */
    .toggle-wrap { display:inline-flex; align-items:center; justify-content:center; }
    .toggle-wrap input[type="checkbox"] { display:none; }
    .toggle-wrap label {
        position:relative; display:inline-block;
        width:40px; height:22px; cursor:pointer;
    }
    .toggle-wrap label::before {
        content:'';
        position:absolute; inset:0;
        background:#d1d5db; border-radius:99px;
        transition:background .2s;
    }
    .toggle-wrap label::after {
        content:'';
        position:absolute; top:3px; left:3px;
        width:16px; height:16px; border-radius:50%;
        background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.2);
        transition:transform .2s;
    }
    .toggle-wrap input:checked + label::before { background:#2563eb; }
    .toggle-wrap input:checked + label::after  { transform:translateX(18px); }

    /* ── Save bar ── */
    .save-bar {
        display:flex; align-items:center; justify-content:flex-end; gap:12px;
        padding:16px 20px; background:#f8fafc; border-top:1px solid #e5e7eb;
    }
    .btn-save {
        background:#2563eb; color:#fff; border:none; border-radius:9px;
        padding:9px 22px; font:inherit; font-size:13px; font-weight:600;
        cursor:pointer; transition:background .15s;
    }
    .btn-save:hover { background:#1d4ed8; }
    .save-hint { font-size:12px; color:#9ca3af; }
</style>

<div class="page-header">
    <div>
        <h1>PMS Feature Settings</h1>
        <div class="sub">Control which configuration options are available per PMS provider</div>
    </div>
    <a href="{{ route('inbound.clients.index') }}">← Back to Clients</a>
</div>

<div class="page-body">

    @if(session('success'))
    <div class="alert alert-success">
        <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/></svg>
        {{ session('success') }}
    </div>
    @endif

    @if($errors->any())
    <div class="alert alert-error">
        <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
        {{ $errors->first() }}
    </div>
    @endif

    <form method="POST" action="{{ route('inbound.settings.pms-features.update') }}">
        @csrf

        <div class="matrix-card">
            <div class="matrix-card-header">
                <div>
                    <h2>Feature Availability by Provider</h2>
                    <p>Toggle a feature ON or OFF for each PMS. Changes take effect within 5 minutes (cache TTL).</p>
                </div>
            </div>

            <div style="overflow-x:auto;">
                <table class="matrix-table">
                    <thead>
                        <tr>
                            <th class="feature-col">Feature</th>
                            @foreach($providers as $providerKey => $providerLabel)
                            <th>{{ $providerLabel }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($features as $featureKey => $feature)
                        <tr>
                            <td class="feature-label">
                                <div class="feature-name">{{ $feature['label'] }}</div>
                                @if(!empty($feature['description']))
                                <div class="feature-desc">{{ $feature['description'] }}</div>
                                @endif
                            </td>
                            @foreach($providers as $providerKey => $providerLabel)
                            <td class="toggle-cell">
                                <div class="toggle-wrap">
                                    <input
                                        type="checkbox"
                                        id="flag_{{ $providerKey }}_{{ $featureKey }}"
                                        name="flags[{{ $providerKey }}][{{ $featureKey }}]"
                                        value="1"
                                        {{ ($matrix[$providerKey][$featureKey] ?? true) ? 'checked' : '' }}
                                    >
                                    <label for="flag_{{ $providerKey }}_{{ $featureKey }}"
                                           title="{{ $providerLabel }}: {{ $feature['label'] }}"></label>
                                </div>
                            </td>
                            @endforeach
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="save-bar">
                <span class="save-hint">Unchecked = hidden from merchants for that PMS</span>
                <button type="submit" class="btn-save">Save Settings</button>
            </div>
        </div>

    </form>

</div>
</x-inbound::layouts.master>
