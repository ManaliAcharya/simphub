<x-inbound::layouts.master>
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background:#f7f8fa; margin:0; }
          h1 { font-size:12px; color:#6b7280; text-transform:uppercase; letter-spacing:.08em; margin:0 0 6px; }
          h2 { font-size:22px; margin:0 0 6px; color:#111827; }
          p.sub { color:#6b7280; margin:0 0 0px; font-size:14px; line-height:1.5; }
          label { display:block; font-weight:600; font-size:13px; margin:18px 0 8px; color:#374151; }
          input[type=text]{ width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; box-sizing:border-box; }
          input[type=text]:focus{ outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.15); }

          .pms-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-top:8px; }
          .pms-tile, .gw-tile {
            border:1.5px solid #e5e7eb; border-radius:10px; padding:22px 12px 16px;
            text-align:center; cursor:pointer; background:#fff; transition:all .15s;
            position:relative; user-select:none;
          }
          .pms-tile:hover, .gw-tile:hover { border-color:#9ca3af; box-shadow:0 2px 8px rgba(0,0,0,.06); }
          .pms-tile.selected { border-color:#2563eb; background:#eff6ff; }
          .gw-tile.selected  { border-color:#10b981; background:#ecfdf5; }
          .pms-tile.selected::after, .gw-tile.selected::after{
            content:"✓"; position:absolute; top:8px; right:10px;
            color:#fff; font-size:11px; width:18px; height:18px;
            border-radius:50%; display:flex; align-items:center; justify-content:center;
            font-weight:bold;
          }
          .pms-tile.selected::after { background:#2563eb; }
          .gw-tile.selected::after  { background:#10b981; }
          .logo-box {
            height:50px; display:flex; align-items:center; justify-content:center;
            margin-bottom:10px;
          }
          .logo-box svg { max-height:42px; max-width:150px; }
          .pms-name, .gw-name { font-size:13px; font-weight:600; color:#111827; }
          .gw-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-top:8px; }

          .pms-logo-img, .payment-logo-img {
            max-width:100%; max-height:100%; object-fit:contain; display:block;
          }

          .actions { display:flex; justify-content:flex-end; gap:10px; margin-top:32px; padding-top:20px; border-top:1px solid #f3f4f6; }
          .btn { padding:10px 20px; border-radius:8px; font-size:14px; cursor:pointer; border:1px solid #d1d5db; background:#fff; font-weight:500; }
          .btn.primary { background:#2563eb; color:#fff; border-color:#2563eb; }
          .btn.primary:hover { background:#1d4ed8; }

          @media(max-width:720px){ .pms-grid{ grid-template-columns:repeat(2,1fr);} }
    </style>
    <div class="shell">
        <section class="panel">
            <p class="eyebrow">Client Onboarding</p>
            <h2>Create PMS Client</h2>
            <p class="sub">This creates the client record and generates the unique PMS client id that will be used across webhook, API, and invoice flows.</p>

            <form method="POST" action="{{ route('inbound.clients.store') }}" class="form-grid">
                @csrf

                <label>Client name</label>
                <input type="text" placeholder="e.g. Acme Law Firm" name="client_name" value="{{ old('client_name') }}" required>

                <label class="field-label">Select PMS</label>
                <input type="hidden" name="client_pms" id="client_pms_input" value="{{ old('client_pms', 'CLIO') }}" required>
                <div class="pms-grid">
                    <div class="pms-tile {{ old('client_pms', 'CLIO') === 'CLIO' ? 'selected' : '' }}"
                         data-value="CLIO" onclick="selectPMS(this)">
                        <div class="logo-box">
                            <img src="{{ asset('images/clio_logo.png') }}" alt="Clio Logo" class="pms-logo-img">
                        </div>
                        <div class="pms-name">Clio</div>
                    </div>
                    <div class="pms-tile {{ old('client_pms') === 'ZOHO' ? 'selected' : '' }}"
                         data-value="ZOHO" onclick="selectPMS(this)">
                        <div class="logo-box">
                            <img src="{{ asset('images/zoho_logo.png') }}" alt="Zoho Logo" class="pms-logo-img">
                        </div>
                        <div class="pms-name">Zoho Books</div>
                    </div>
                    <div class="pms-tile {{ old('client_pms') === 'LAWCUS' ? 'selected' : '' }}"
                         data-value="LAWCUS" onclick="selectPMS(this)">
                        <div class="logo-box">
                            <img src="{{ asset('images/lawcus_logo.png') }}" alt="Lawcus Logo" class="pms-logo-img">
                        </div>
                        <div class="pms-name">Lawcus</div>
                    </div>
                    <div class="pms-tile {{ old('client_pms') === 'QUICKBOOKS' ? 'selected' : '' }}"
                         data-value="QUICKBOOKS" onclick="selectPMS(this)">
                        <div class="logo-box">
                            <img src="{{ asset('images/qb_logo.png') }}" alt="QuickBooks Logo" class="pms-logo-img">
                        </div>
                        <div class="pms-name">QuickBooks</div>
                    </div>
                    <div class="pms-tile {{ old('client_pms') === 'CUSTOM' ? 'selected' : '' }}"
                         data-value="CUSTOM" onclick="selectPMS(this)">
                        <div class="logo-box">
                            <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="3"/>
                                <path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/>
                                <path d="M12 2v2M12 20v2M2 12h2M20 12h2"/>
                            </svg>
                        </div>
                        <div class="pms-name">Custom</div>
                    </div>
                </div>

                <div id="zoho-region-field" style="{{ old('client_pms') === 'ZOHO' ? '' : 'display:none;' }}">
                    <label class="field-label">Zoho account region</label>
                    <input type="hidden" name="zoho_region" id="zoho_region_input" value="{{ old('zoho_region') }}">
                    <div class="pms-grid">
                        @foreach ($zohoRegions as $code => $label)
                            <div class="pms-tile region-tile {{ old('zoho_region') === $code ? 'selected' : '' }}"
                                 data-value="{{ $code }}" onclick="selectRegion(this)">
                                <div class="logo-box">
                                    <img src="{{ asset('images/flags/' . $code . '.png') }}" alt="{{ $label }} flag" class="pms-logo-img">
                                </div>
                                <div class="pms-name">{{ $label }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Payment Gateways --}}
                <label class="field-label">Allowed payment gateways <span style="font-weight:400;color:#6b7280;">(select one or more)</span></label>
                <div class="gw-grid">
                    @forelse ($availableGateways as $gateway)
                        @php $isChecked = in_array($gateway, old('allowed_payment_gateways', []), true); @endphp
                        <div class="gw-tile {{ $isChecked ? 'selected' : '' }}" onclick="toggleGW(this)">
                            <input type="checkbox" name="allowed_payment_gateways[]" value="{{ $gateway }}" style="display:none;" @checked($isChecked)>
                            <div class="logo-box">
                                @if(strtoupper($gateway) === 'FLUIDPAY')
                                    <img src="{{ asset('images/fluidpay_logo.png') }}" alt="FluidPay Logo" class="payment-logo-img">
                                @elseif(strtoupper($gateway) === 'PAYA')
                                    <img src="{{ asset('images/paya_logo.png') }}" alt="Paya Logo" class="payment-logo-img">
                                @elseif(strtoupper($gateway) === 'NMI')
                                    <img src="{{ asset('images/nmi_logo.png') }}" alt="NMI Logo" class="payment-logo-img">
                                @else
                                    <div style="width:40px;height:40px;border-radius:8px;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;color:#6b7280;">{{ substr($gateway,0,1) }}</div>
                                @endif
                            </div>
                            <div class="gw-name">{{ $gateway }}</div>
                        </div>
                    @empty
                        <p style="font-size:13px;color:#6b7280;margin:0;">No active routing rules found yet. Add routing rules first.</p>
                    @endforelse
                </div>

                {{-- Terminals / Equipment --}}
                {{-- TEMPORARILY COMMENTED OUT
                <label class="field-label">Allowed equipment / terminals <span style="font-weight:400;color:#6b7280;">(select one or more)</span></label>
                @php $terminalOld = old('allowed_terminals', []); @endphp
                <div class="gw-grid">
                    @forelse ($availableTerminals as $terminal)
                        @php $isChecked = in_array($terminal, $terminalOld, true); @endphp
                        <div class="gw-tile {{ $isChecked ? 'selected' : '' }}" onclick="toggleGW(this)">
                            <input type="checkbox" name="allowed_terminals[]" value="{{ $terminal }}" style="display:none;" @checked($isChecked)>
                            <div class="logo-box">
                                <img src="{{ asset('images/' . strtolower($terminal) . '_logo.png') }}"
                                     alt="{{ $terminal }} Logo" class="payment-logo-img"
                                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                <div style="display:none;width:40px;height:40px;border-radius:8px;background:#e5e7eb;align-items:center;justify-content:center;font-weight:700;font-size:16px;color:#6b7280;">
                                    {{ substr($terminal, 0, 1) }}
                                </div>
                            </div>
                            <div class="gw-name">{{ ucfirst(strtolower($terminal)) }}</div>
                        </div>
                    @empty
                        <p style="font-size:13px;color:#6b7280;margin:0;">No terminal configurations found yet. Add terminal configurations first.</p>
                    @endforelse
                </div>
                END TEMPORARILY COMMENTED OUT --}}

                <input type="hidden" name="webhook_flow_enabled" value="1">
                <input type="hidden" name="call_api_to_pms" value="1">
                <input type="hidden" name="client_calls_our_api" value="0">

                <div class="actions">
                    <button class="button primary" type="submit">Create Client</button>
                </div>
            </form>

            <script>
                (function () {
                    const pmsInput = document.getElementById('client_pms_input');
                    const zohoRegionField = document.getElementById('zoho-region-field');
                    const zohoRegionInput = document.getElementById('zoho_region_input');

                    function toggleZohoRegion() {
                        const show = pmsInput.value === 'ZOHO';
                        zohoRegionField.style.display = show ? '' : 'none';
                        if (!show) {
                            zohoRegionInput.value = '';
                            document.querySelectorAll('.region-tile').forEach(t => t.classList.remove('selected'));
                        }
                    }
                    pmsInput.addEventListener('change', toggleZohoRegion);
                    toggleZohoRegion();

                    window.selectRegion = function (element) {
                        document.querySelectorAll('.region-tile').forEach(t => t.classList.remove('selected'));
                        element.classList.add('selected');
                        zohoRegionInput.value = element.getAttribute('data-value');
                    };
                }());

                function selectPMS(element) {
                    document.querySelectorAll('.pms-tile').forEach(t => t.classList.remove('selected'));
                    element.classList.add('selected');
                    const input = document.getElementById('client_pms_input');
                    input.value = element.getAttribute('data-value');
                    input.dispatchEvent(new Event('change'));
                }

                function toggleGW(tile) {
                    const checkbox = tile.querySelector('input[type="checkbox"]');
                    checkbox.checked = !checkbox.checked;
                    tile.classList.toggle('selected', checkbox.checked);
                    checkbox.dispatchEvent(new Event('change'));
                }
            </script>

            @if ($errors->any())
                <div class="notice error">
                    {{ $errors->first() }}
                </div>
            @endif
        </section>
    </div>
</x-inbound::layouts.master>
