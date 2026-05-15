<x-inbound::layouts.master>
<style>
    body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background:#f7f8fa; margin:0; }
    h2   { font-size:22px; margin:0 0 6px; color:#111827; }
    p.sub { color:#6b7280; margin:0 0 0; font-size:14px; line-height:1.5; }

    input[type=text] { width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; box-sizing:border-box; }
    input[type=text]:focus { outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.15); }

    /* ── Section boxes ───────────────────────────────────── */
    .section-box {
        border:1.5px solid #e5e7eb; border-radius:12px;
        padding:20px 24px 24px; margin-top:24px; background:#fff;
        position:relative;
    }
    .section-legend {
        position:absolute; top:-11px; left:16px;
        background:#fff; padding:0 8px;
        font-size:11px; font-weight:700; text-transform:uppercase;
        letter-spacing:.08em; color:#6b7280;
    }

    /* ── Integration type radios ─────────────────────────── */
    .int-type-row { display:flex; gap:16px; margin-bottom:20px; }
    .int-type-card {
        flex:1; border:1.5px solid #e5e7eb; border-radius:10px;
        padding:14px 18px; cursor:pointer; display:flex; align-items:flex-start;
        gap:12px; transition:all .15s; background:#fff;
    }
    .int-type-card:hover { border-color:#9ca3af; }
    .int-type-card.active { border-color:#2563eb; background:#eff6ff; }
    .int-type-card input[type=radio] { margin-top:2px; accent-color:#2563eb; flex-shrink:0; }
    .int-type-card .card-title { font-size:14px; font-weight:600; color:#111827; }
    .int-type-card .card-sub   { font-size:12px; color:#6b7280; margin-top:2px; }
    .int-divider { border:none; border-top:1px solid #f3f4f6; margin:0 0 20px; }

    /* ── Tile grids ──────────────────────────────────────── */
    .field-label {
        display:block; font-weight:600; font-size:12px;
        text-transform:uppercase; letter-spacing:.06em;
        color:#6b7280; margin:0 0 10px;
    }
    .tile-row { margin-bottom:20px; }
    .tile-row:last-child { margin-bottom:0; }

    .tile-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
    @media(max-width:720px){ .tile-grid { grid-template-columns:repeat(2,1fr); } }

    .tile {
        border:1.5px solid #e5e7eb; border-radius:10px; padding:18px 10px 14px;
        text-align:center; cursor:pointer; background:#fff; transition:all .15s;
        position:relative; user-select:none;
    }
    .tile:hover { box-shadow:0 2px 8px rgba(0,0,0,.06); }

    /* PMS — blue */
    .tile.pms:hover   { border-color:#93c5fd; }
    .tile.pms.selected { border-color:#2563eb; background:#eff6ff; }
    .tile.pms.selected::after {
        content:"✓"; position:absolute; top:7px; right:8px;
        background:#2563eb; color:#fff; font-size:10px; font-weight:700;
        width:17px; height:17px; border-radius:50%;
        display:flex; align-items:center; justify-content:center;
    }

    /* Gateway — green */
    .tile.gw:hover    { border-color:#6ee7b7; }
    .tile.gw.selected { border-color:#10b981; background:#ecfdf5; }
    .tile.gw.selected::after {
        content:"✓"; position:absolute; top:7px; right:8px;
        background:#10b981; color:#fff; font-size:10px; font-weight:700;
        width:17px; height:17px; border-radius:50%;
        display:flex; align-items:center; justify-content:center;
    }

    /* Terminal — purple */
    .tile.terminal:hover    { border-color:#c4b5fd; }
    .tile.terminal.selected { border-color:#7c3aed; background:#f5f3ff; }
    .tile.terminal.selected::after {
        content:"✓"; position:absolute; top:7px; right:8px;
        background:#7c3aed; color:#fff; font-size:10px; font-weight:700;
        width:17px; height:17px; border-radius:50%;
        display:flex; align-items:center; justify-content:center;
    }

    .tile .logo-box {
        height:44px; display:flex; align-items:center;
        justify-content:center; margin-bottom:8px;
    }
    .tile .logo-box img { max-width:100%; max-height:100%; object-fit:contain; }
    .tile .tile-name { font-size:12px; font-weight:600; color:#111827; }

    /* ── Actions ─────────────────────────────────────────── */
    .actions { display:flex; justify-content:flex-end; gap:10px; margin-top:28px; padding-top:20px; border-top:1px solid #f3f4f6; }
    .btn          { padding:10px 20px; border-radius:8px; font-size:14px; cursor:pointer; border:1px solid #d1d5db; background:#fff; font-weight:500; }
    .btn.primary  { background:#2563eb; color:#fff; border-color:#2563eb; }
    .btn.primary:hover { background:#1d4ed8; }
</style>

<div class="shell">
    <section class="panel">
        <p class="eyebrow">Client Onboarding</p>
        <h2>Create Client</h2>
        <p class="sub">Creates the client record and generates a unique PMS client ID used across all API, webhook, and invoice flows.</p>

        <form method="POST" action="{{ route('inbound.clients.store') }}">
            @csrf

            {{-- Client name --}}
            <label style="display:block;font-weight:600;font-size:13px;margin:20px 0 8px;color:#374151;">Client name</label>
            <input type="text" placeholder="e.g. Acme Law Firm" name="client_name" value="{{ old('client_name') }}" required>

            {{-- ══════════════════════════════════════════════════════
                 ONLINE PAYMENTS
            ═══════════════════════════════════════════════════════ --}}
            <div class="section-box">
                <span class="section-legend">Online Payments <span style="font-weight:400;">(optional)</span></span>

                {{-- Integration type toggle --}}
                <div class="int-type-row">
                    <label class="int-type-card {{ old('integration_type', 'pms') === 'pms' ? 'active' : '' }}" id="card-pms">
                        <input type="radio" name="integration_type" value="pms"
                               {{ old('integration_type', 'pms') === 'pms' ? 'checked' : '' }}
                               onchange="setIntegrationType('pms')">
                        <div>
                            <div class="card-title">Use a PMS</div>
                            <div class="card-sub">Clio, Zoho, Lawcus, QuickBooks</div>
                        </div>
                    </label>
                    <label class="int-type-card {{ old('integration_type') === 'custom' ? 'active' : '' }}" id="card-custom">
                        <input type="radio" name="integration_type" value="custom"
                               {{ old('integration_type') === 'custom' ? 'checked' : '' }}
                               onchange="setIntegrationType('custom')">
                        <div>
                            <div class="card-title">Custom Integration</div>
                            <div class="card-sub">API only — no PMS</div>
                        </div>
                    </label>
                </div>

                <hr class="int-divider">

                {{-- PMS row (hidden when Custom is selected) --}}
                <div class="tile-row" id="pms-row">
                    <span class="field-label" style="color:#2563eb;">Practice Management System</span>
                    <input type="hidden" name="client_pms" id="client_pms_input" value="{{ old('client_pms', 'CLIO') }}">
                    <div class="tile-grid">
                        <div class="tile pms {{ old('client_pms', 'CLIO') === 'CLIO' ? 'selected' : '' }}" data-value="CLIO" onclick="selectPMS(this)">
                            <div class="logo-box"><img src="{{ asset('images/clio_logo.png') }}" alt="Clio"></div>
                            <div class="tile-name">Clio</div>
                        </div>
                        <div class="tile pms {{ old('client_pms') === 'ZOHO' ? 'selected' : '' }}" data-value="ZOHO" onclick="selectPMS(this)">
                            <div class="logo-box"><img src="{{ asset('images/zoho_logo.png') }}" alt="Zoho"></div>
                            <div class="tile-name">Zoho Books</div>
                        </div>
                        <div class="tile pms {{ old('client_pms') === 'LAWCUS' ? 'selected' : '' }}" data-value="LAWCUS" onclick="selectPMS(this)">
                            <div class="logo-box"><img src="{{ asset('images/lawcus_logo.png') }}" alt="Lawcus"></div>
                            <div class="tile-name">Lawcus</div>
                        </div>
                        <div class="tile pms {{ old('client_pms') === 'QUICKBOOKS' ? 'selected' : '' }}" data-value="QUICKBOOKS" onclick="selectPMS(this)">
                            <div class="logo-box"><img src="{{ asset('images/qb_logo.png') }}" alt="QuickBooks"></div>
                            <div class="tile-name">QuickBooks</div>
                        </div>
                    </div>
                </div>

                {{-- Zoho region (shown only when Zoho is selected) --}}
                <div class="tile-row" id="zoho-region-field" style="{{ old('client_pms') === 'ZOHO' ? '' : 'display:none;' }}">
                    <span class="field-label" style="color:#2563eb;">Zoho Account Region</span>
                    <input type="hidden" name="zoho_region" id="zoho_region_input" value="{{ old('zoho_region') }}">
                    <div class="tile-grid">
                        @foreach ($zohoRegions as $code => $label)
                            <div class="tile pms region-tile {{ old('zoho_region') === $code ? 'selected' : '' }}"
                                 data-value="{{ $code }}" onclick="selectRegion(this)">
                                <div class="logo-box"><img src="{{ asset('images/flags/' . $code . '.png') }}" alt="{{ $label }}"></div>
                                <div class="tile-name">{{ $label }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Gateways --}}
                <div class="tile-row">
                    <span class="field-label" style="color:#059669;">Payment Gateways</span>
                    <div class="tile-grid">
                        @forelse ($availableGateways as $gateway)
                            @php $isChecked = in_array($gateway, old('allowed_payment_gateways', []), true); @endphp
                            <div class="tile gw {{ $isChecked ? 'selected' : '' }}" onclick="toggleTile(this)">
                                <input type="checkbox" name="allowed_payment_gateways[]" value="{{ $gateway }}" style="display:none;" @checked($isChecked)>
                                <div class="logo-box">
                                    @if(strtoupper($gateway) === 'FLUIDPAY')
                                        <img src="{{ asset('images/fluidpay_logo.png') }}" alt="FluidPay">
                                    @elseif(strtoupper($gateway) === 'PAYA')
                                        <img src="{{ asset('images/paya_logo.png') }}" alt="Paya">
                                    @elseif(strtoupper($gateway) === 'NMI')
                                        <img src="{{ asset('images/nmi_logo.png') }}" alt="NMI">
                                    @else
                                        <div style="width:40px;height:40px;border-radius:8px;background:#d1fae5;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;color:#059669;">{{ substr($gateway,0,1) }}</div>
                                    @endif
                                </div>
                                <div class="tile-name">{{ $gateway }}</div>
                            </div>
                        @empty
                            <p style="font-size:13px;color:#6b7280;margin:0;grid-column:1/-1;">No active routing rules found. Add routing rules first.</p>
                        @endforelse
                    </div>
                </div>

            </div>{{-- /online payments --}}

            {{-- ══════════════════════════════════════════════════════
                 IN-PERSON PAYMENTS
            ═══════════════════════════════════════════════════════ --}}
            <!--<div class="section-box">
                <span class="section-legend">In-Person Payments <span style="font-weight:400;">(optional)</span></span>

                <div class="tile-row" style="margin-bottom:0;">
                    <span class="field-label" style="color:#7c3aed;">Equipment / Terminals</span>
                    <p style="font-size:13px;color:#6b7280;margin:0 0 14px;line-height:1.6;">Used to provision and sync terminal config (VAR data, TPN/EPI, MID). Transactions run through the underlying processor, not the middleware.</p>
                    @php $terminalOld = old('allowed_terminals', []); @endphp
                    <div class="tile-grid">
                        @forelse ($availableTerminals as $terminal)
                            @php $isChecked = in_array($terminal, $terminalOld, true); @endphp
                            <div class="tile terminal {{ $isChecked ? 'selected' : '' }}" onclick="toggleTile(this)">
                                <input type="checkbox" name="allowed_terminals[]" value="{{ $terminal }}" style="display:none;" @checked($isChecked)>
                                <div class="logo-box">
                                    <img src="{{ asset('images/' . strtolower($terminal) . '_logo.png') }}"
                                         alt="{{ $terminal }}"
                                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                    <div style="display:none;width:40px;height:40px;border-radius:8px;background:#ede9fe;align-items:center;justify-content:center;font-weight:700;font-size:16px;color:#7c3aed;">{{ substr($terminal,0,1) }}</div>
                                </div>
                                <div class="tile-name">{{ ucfirst(strtolower($terminal)) }}</div>
                            </div>
                        @empty
                            <p style="font-size:13px;color:#6b7280;margin:0;grid-column:1/-1;">No terminal configurations found. Add terminal configurations first.</p>
                        @endforelse
                    </div>
                </div>
            </div> -->{{-- /in-person payments --}}

            <input type="hidden" name="webhook_flow_enabled" value="1">
            <input type="hidden" name="call_api_to_pms" value="1">
            <input type="hidden" name="client_calls_our_api" value="0">

            <div class="actions">
                <a href="{{ route('inbound.clients.create') }}" class="btn">Cancel</a>
                <button type="submit" class="btn primary">Create Client</button>
            </div>
        </form>

        @if ($errors->any())
            <div class="notice error" style="margin-top:16px;">{{ $errors->first() }}</div>
        @endif
    </section>
</div>

<script>
    const pmsInput       = document.getElementById('client_pms_input');
    const zohoRegionField = document.getElementById('zoho-region-field');
    const zohoRegionInput = document.getElementById('zoho_region_input');
    const pmsRow         = document.getElementById('pms-row');

    // ── Integration type ────────────────────────────────────
    window.setIntegrationType = function (type) {
        document.getElementById('card-pms').classList.toggle('active', type === 'pms');
        document.getElementById('card-custom').classList.toggle('active', type === 'custom');

        if (type === 'custom') {
            pmsRow.style.display = 'none';
            zohoRegionField.style.display = 'none';
            pmsInput.value = 'CUSTOM';
            document.querySelectorAll('.tile.pms').forEach(t => t.classList.remove('selected'));
        } else {
            pmsRow.style.display = '';
            pmsInput.value = pmsInput.value === 'CUSTOM' ? 'CLIO' : pmsInput.value;
            // re-highlight the previously selected PMS tile
            document.querySelectorAll('.tile.pms:not(.region-tile)').forEach(t => {
                t.classList.toggle('selected', t.dataset.value === pmsInput.value);
            });
            toggleZohoRegion();
        }
    };

    // ── PMS tile selection ──────────────────────────────────
    window.selectPMS = function (element) {
        document.querySelectorAll('.tile.pms:not(.region-tile)').forEach(t => t.classList.remove('selected'));
        element.classList.add('selected');
        pmsInput.value = element.dataset.value;
        toggleZohoRegion();
    };

    // ── Zoho region ─────────────────────────────────────────
    function toggleZohoRegion() {
        const show = pmsInput.value === 'ZOHO';
        zohoRegionField.style.display = show ? '' : 'none';
        if (!show) {
            zohoRegionInput.value = '';
            document.querySelectorAll('.region-tile').forEach(t => t.classList.remove('selected'));
        }
    }

    window.selectRegion = function (element) {
        document.querySelectorAll('.region-tile').forEach(t => t.classList.remove('selected'));
        element.classList.add('selected');
        zohoRegionInput.value = element.dataset.value;
    };

    // ── Checkbox tiles (gateways + terminals) ───────────────
    window.toggleTile = function (tile) {
        const cb = tile.querySelector('input[type="checkbox"]');
        cb.checked = !cb.checked;
        tile.classList.toggle('selected', cb.checked);
    };

    // Init on page load
    (function () {
        const intType = '{{ old('integration_type', 'pms') }}';
        if (intType === 'custom') setIntegrationType('custom');
        else toggleZohoRegion();
    }());
</script>
</x-inbound::layouts.master>
