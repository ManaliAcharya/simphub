<x-inbound::layouts.master>
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background:#f7f8fa; margin:0; }
          .card { background:#fff; max-width:820px; margin:auto; border:1px solid #e5e7eb; border-radius:12px; padding:32px; }
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
            margin-bottom:12px;
          }
          .logo-box svg { max-height:42px; max-width:150px; }
          .pms-name, .gw-name { font-size:13px; font-weight:600; color:#111827; }
          .gw-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-top:8px; }

          .actions { display:flex; justify-content:flex-end; gap:10px; margin-top:32px; padding-top:20px; border-top:1px solid #f3f4f6; }
          .btn { padding:10px 20px; border-radius:8px; font-size:14px; cursor:pointer; border:1px solid #d1d5db; background:#fff; font-weight:500; }
          .btn.primary { background:#2563eb; color:#fff; border-color:#2563eb; }
          .btn.primary:hover { background:#1d4ed8; }

          @media(max-width:720px){ .pms-grid{ grid-template-columns:repeat(2,1fr);} }

            .pms-logo-img {
                max-width: 100%;
                max-height: 100%;
                object-fit: contain; /* Maintains aspect ratio without stretching */
                display: block;
            }
            .payment-logo-img {
                max-width: 100%;
                max-height: 100%;
                object-fit: contain; /* Maintains aspect ratio without stretching */
                display: block;
            }

            /* Ensure the container (logo-box) remains the same height as before */
            .logo-box {
                height: 50px; 
                display: flex;
                justify-content: center;
                align-items: center;
                margin-bottom: 10px;
            }
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

                <!-- <label class="field">
                    <span>Client PMS</span>
                    <select id="client-pms-select" name="client_pms" required>
                        <option value="CLIO" @selected(old('client_pms') === 'CLIO')>Clio</option>
                        <option value="ZOHO" @selected(old('client_pms') === 'ZOHO')>Zoho</option>
                    </select>
                </label> -->

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
                </div>

                <!-- <label class="field" id="zoho-region-field" style="{{ old('client_pms') === 'ZOHO' ? '' : 'display:none;' }}">
                    <span>Zoho account region</span>
                    <select name="zoho_region">
                        <option value="">Select region</option>
                        @foreach ($zohoRegions as $code => $label)
                            <option value="{{ $code }}" @selected(old('zoho_region') === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label> -->
                <div id="zoho-region-field" style="{{ old('client_pms') === 'ZOHO' ? '' : 'display:none;' }}">
                    <label class="field-label">Zoho account region</label>
                    
                    <input type="hidden" name="zoho_region" id="zoho_region_input" value="{{ old('zoho_region') }}">

                    <div class="pms-grid">
                        @foreach ($zohoRegions as $code => $label)
                            <div class="pms-tile region-tile {{ old('zoho_region') === $code ? 'selected' : '' }}" 
                                 data-value="{{ $code }}" 
                                 onclick="selectRegion(this)">
                                 <div class="logo-box">
                                    <img src="{{ asset('images/flags/' . $code . '.png') }}" 
                                         alt="{{ $label }} flag" 
                                         class="pms-logo-img">
                                </div>
                                <div class="pms-name">{{ $label }}</div><!-- 
                                <div class="small-text">{{ $code }}</div> -->
                            </div>
                        @endforeach
                    </div>
                </div>
                <!-- <p class="copy" id="zoho-account-help" style="{{ old('client_pms') === 'ZOHO' ? '' : 'display:none;' }}">
                    Default deposit account will be selected from Zoho account dropdown after Zoho connection is completed.
                </p> -->

                <label class="field-label">Allowed payment gateways <span style="font-weight:400;color:#6b7280;">(select one or more)</span></label>

                <div class="gw-grid">
                    @forelse ($availableGateways as $gateway)
                        @php
                            $isChecked = in_array($gateway, old('allowed_payment_gateways', []), true);
                            // Optional: Map gateway names to specific SVG logos if you have them, 
                            // otherwise use a generic placeholder or the gateway name.
                        @endphp
                        
                        <div class="gw-tile {{ $isChecked ? 'selected' : '' }}" onclick="toggleGW(this)">
                            <input 
                                type="checkbox" 
                                name="allowed_payment_gateways[]" 
                                value="{{ $gateway }}" 
                                style="display: none;"
                                @checked($isChecked)
                            >
                            
                            <div class="logo-box">
                                @if(strtoupper($gateway) === 'FLUIDPAY')
                                    <img src="{{ asset('images/fluidpay_logo.png') }}" alt="FluidPay Logo" class="payment-logo-img">
                                @elseif(strtoupper($gateway) === 'PAYA')
                                    <img src="{{ asset('images/paya_logo.png') }}" alt="Paya Logo" class="payment-logo-img">
                                @else
                                    <div class="gateway-placeholder">{{ substr($gateway, 0, 1) }}</div>
                                @endif
                            </div>
                            <div class="gw-name">{{ $gateway }}</div>
                        </div>
                    @empty
                        <p class="copy">No active routing rules were found yet. Add routing rules first to choose payment gateways for this client.</p>
                    @endforelse
                </div>
                <input type="hidden" name="webhook_flow_enabled" value="1">
                <input type="hidden" name="call_api_to_pms" value="1">
                <input type="hidden" name="client_calls_our_api" value="0">
                <!-- <label class="checkbox">
                    <input type="hidden" name="webhook_flow_enabled" value="0">
                    <input type="checkbox" name="webhook_flow_enabled" value="1" @checked(old('webhook_flow_enabled'))>
                    <span>Webhook flow</span>
                </label>

                <label class="checkbox">
                    <input type="hidden" name="call_api_to_pms" value="0">
                    <input type="checkbox" name="call_api_to_pms" value="1" @checked(old('call_api_to_pms'))>
                    <span>Call API to PMS</span>
                </label>

                <label class="checkbox">
                    <input type="hidden" name="client_calls_our_api" value="0">
                    <input type="checkbox" name="client_calls_our_api" value="1" @checked(old('client_calls_our_api'))>
                    <span>Client will call our API</span>
                </label> -->

                <div class="actions">
                    <button class="button primary" type="submit">Create Client</button>
                    <!-- <a class="button secondary" href="{{ route('inbound.clio.page') }}">Back to Clio</a> -->
                </div>
            </form>

            <script>
                (function () {
                    const pmsSelect = document.getElementById('client-pms-select');
                    const pmsInput = document.getElementById('client_pms_input');
                    const zohoRegionField = document.getElementById('zoho-region-field');
                    const zohoRegionInput = document.getElementById('zoho_region_input');
                    //const zohoAccountHelp = document.getElementById('zoho-account-help');

                    function toggleZohoRegion() {
                        const showZohoFields = pmsInput.value === 'ZOHO';
                        zohoRegionField.style.display = showZohoFields ? '' : 'none';
                        //zohoAccountHelp.style.display = showZohoFields ? '' : 'none';
                        if (!showZohoFields) {
                            zohoRegionInput.value = '';
                            document.querySelectorAll('.region-tile').forEach(t => t.classList.remove('selected'));
                        }
                    }
                    pmsInput.addEventListener('change', toggleZohoRegion);
                    //pmsSelect.addEventListener('change', toggleZohoRegion);
                    toggleZohoRegion();

                    window.selectRegion = function(element) {
                        // 1. UI Toggle
                        document.querySelectorAll('.region-tile').forEach(tile => tile.classList.remove('selected'));
                        element.classList.add('selected');

                        // 2. Value Assignment
                        const val = element.getAttribute('data-value');
                        zohoRegionInput.value = val;
                    };
                }());
                function selectPMS(element) {
                    const tiles = document.querySelectorAll('.pms-tile');
                    tiles.forEach(tile => tile.classList.remove('selected'));
                    element.classList.add('selected');
                    const val = element.getAttribute('data-value');
                    const input = document.getElementById('client_pms_input');
                    input.value = val;
                    document.getElementById('client_pms_input').dispatchEvent(new Event('change'));
                    input.dispatchEvent(new Event('change'));
                }
                function toggleGW(tile) {
                    // 1. Find the checkbox inside this tile
                    const checkbox = tile.querySelector('input[type="checkbox"]');
                    
                    // 2. Toggle the checked state
                    checkbox.checked = !checkbox.checked;
                    
                    // 3. Toggle the visual "selected" class
                    if (checkbox.checked) {
                        tile.classList.add('selected');
                    } else {
                        tile.classList.remove('selected');
                    }

                    // Optional: Dispatch change event if other scripts need to listen
                    checkbox.dispatchEvent(new Event('change'));
                }
            </script>

            @if ($errors->any())
                <div class="notice error">
                    {{ $errors->first() }}
                </div>
            @endif
        </section>

        <!-- <section class="panel">
            <p class="eyebrow">Configured Clients</p>
            <h2>Existing PMS Clients</h2>

            @if ($clients->isEmpty())
                <p class="empty">No clients have been created yet.</p>
            @else
                <div class="client-list">
                    @foreach ($clients as $client)
                        <div class="client-row">
                            <span>Client</span>
                            <strong>{{ $client->client_name }}</strong>
                            <span>PMS</span>
                            <strong>{{ $client->client_pms }}</strong>
                            <span>PMS client id</span>
                            <code>{{ $client->pms_client_id }}</code>
                            <span>Allowed gateways</span>
                            <strong>{{ collect($client->allowed_payment_gateways)->implode(', ') ?: 'Not configured' }}</strong>
                        </div>
                    @endforeach
                </div>
            @endif
        </section> -->
    </div>
</x-inbound::layouts.master>
