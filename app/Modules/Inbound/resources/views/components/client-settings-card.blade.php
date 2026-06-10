@props([
    'client',
    'availableGateways' => [],
    'showLeftCol'  => true,   // client name + ID
    'showFees'     => true,   // processing fee section
    'showLogo'     => true,   // company logo section
    'showGateways' => true,   // allowed gateways section
    'compact'      => false,  // when true: each section as its own cc-card
])

@php $surcharge = (bool) $client->fee_surcharge_enabled; @endphp

<style>
.csc-tip-wrap { position:relative; display:inline-flex; align-items:center; }
.csc-tip-icon { width:14px; height:14px; border-radius:50%; background:rgba(19,34,56,.12); color:#6b7c93;
    font-size:9px; font-weight:700; display:inline-flex; align-items:center; justify-content:center;
    margin-left:5px; cursor:help; flex-shrink:0; user-select:none; }
.csc-tip-box { display:none; position:absolute; left:calc(100% + 8px); top:50%; transform:translateY(-50%);
    background:#132238; color:#e8edf2; font-size:11.5px; font-weight:400; line-height:1.6;
    padding:10px 13px; border-radius:10px; width:230px; z-index:400;
    text-transform:none; letter-spacing:0; box-shadow:0 4px 18px rgba(0,0,0,.22); pointer-events:none; }
.csc-tip-box::before { content:''; position:absolute; right:100%; top:50%; transform:translateY(-50%);
    border:5px solid transparent; border-right-color:#132238; }
.csc-tip-wrap:hover .csc-tip-box { display:block; }
</style>

@if(!$compact)
<div class="panel" style="padding:0;overflow:hidden;margin-bottom:18px;">
<div style="display:grid;grid-template-columns:{{ $showLeftCol ? '1fr 1fr' : '1fr' }};">
@endif

        {{-- ── Left: client identity ── --}}
        @if($showLeftCol)
        <div style="padding:20px 22px;display:flex;flex-direction:column;gap:18px;border-right:1px solid rgba(19,34,56,.1);">

            <div>
                <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6b7c93;display:block;margin-bottom:6px;">Client</span>
                <strong style="font-size:15px;color:#132238;">{{ $client->client_name }}</strong>
                <span style="display:inline-block;margin-left:8px;font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px;background:#e9eef5;color:#132238;">{{ $client->client_pms }}</span>
            </div>

            <div>
                <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6b7c93;display:block;margin-bottom:6px;">Client ID</span>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <code id="csc-id" style="font-family:ui-monospace,monospace;font-size:12px;background:#f1f5f9;border:1px solid rgba(19,34,56,.1);border-radius:6px;padding:4px 9px;word-break:break-all;">{{ $client->pms_client_id }}</code>
                    <button onclick="cscCopy(this)" style="padding:4px 10px;background:#132238;color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap;">Copy</button>
                </div>
            </div>

        </div>
        @endif

        {{-- ── Right: gateways / logo / fees ── --}}
        @if(!$compact)
        <div style="padding:20px 22px;display:flex;flex-direction:column;gap:18px;">
        @endif

            {{-- Allowed Gateways --}}
            @if($showGateways)
            @if($compact)<div class="cc-card">@else<div>@endif
                <div style="display:flex;align-items:center;margin-bottom:{{ $compact ? '10' : '6' }}px;">
                    @if($compact)
                        <div class="cc-card-title">Allowed Gateways</div>
                    @else
                        <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6b7c93;">Allowed Gateways</span>
                    @endif
                    <span class="csc-tip-wrap">
                        <span class="csc-tip-icon">i</span>
                        <span class="csc-tip-box">Control the payment options visible on this client's invoices. Add or remove gateways instantly.</span>
                    </span>
                </div>
                @php $pausedGateways = array_map('strtoupper', $client->paused_payment_gateways ?? []); @endphp
                <div id="csc-gw-list" style="display:flex;align-items:center;flex-wrap:wrap;gap:5px;">

                    {{-- Active gateways --}}
                    @forelse($client->allowed_payment_gateways ?? [] as $gw)
                        @php $gwUp = strtoupper($gw); $isPaused = in_array($gwUp, $pausedGateways); @endphp
                        <span data-gw="{{ $gwUp }}"
                              style="display:inline-flex;align-items:center;gap:4px;padding:3px 7px 3px 10px;border-radius:999px;font-size:11px;font-weight:600;
                                     {{ $isPaused ? 'background:#f3f4f6;color:#9ca3af;border:1px dashed #d1d5db;' : 'background:#e9eef5;color:#132238;border:1px solid rgba(19,34,56,.12);' }}">
                            {{ $gwUp }}
                            @if($isPaused)
                                <span style="font-size:10px;font-weight:500;color:#9ca3af;margin:0 2px;">(paused)</span>
                            @endif
                            {{-- Pause / Resume --}}
                            <form method="POST" action="{{ route('inbound.clients.toggle-gateway-pause', $client->pms_client_id) }}" style="display:inline;">
                                @csrf
                                <input type="hidden" name="gateway" value="{{ $gwUp }}">
                                <input type="hidden" name="paused" value="{{ $isPaused ? '0' : '1' }}">
                                <button type="submit"
                                        title="{{ $isPaused ? 'Resume' : 'Pause' }}"
                                        style="background:none;border:none;cursor:pointer;font-size:11px;padding:0;line-height:1;color:{{ $isPaused ? '#15803d' : '#f59e0b' }};">
                                    {{ $isPaused ? '▶' : '⏸' }}
                                </button>
                            </form>
                            {{-- Remove --}}
                            <button type="button" onclick="cscRemoveGw('{{ $gwUp }}')"
                                    style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:12px;line-height:1;padding:0;font-weight:700;">&#x00D7;</button>
                        </span>
                    @empty
                        <span id="csc-gw-empty" style="font-size:13px;color:#6b7c93;">None configured</span>
                    @endforelse

                    <div style="position:relative;" id="csc-add-wrap">
                        <button type="button" id="csc-add-btn"
                                style="display:inline-flex;align-items:center;gap:2px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;background:#fff;color:#6b7c93;border:1px dashed rgba(19,34,56,.2);cursor:pointer;">
                            + Add
                        </button>
                        <div id="csc-dd" style="display:none;position:absolute;top:calc(100% + 4px);left:0;min-width:165px;background:#fff;border:1px solid rgba(19,34,56,.1);border-radius:14px;box-shadow:0 6px 20px rgba(19,34,56,.1);z-index:200;overflow:hidden;">
                            <div style="padding:6px 12px 5px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6b7c93;background:#f8f9fb;border-bottom:1px solid rgba(19,34,56,.06);">Available</div>
                            @forelse($availableGateways as $gw)
                                <div data-gw="{{ $gw }}" class="{{ in_array($gw, $client->allowed_payment_gateways ?? []) ? 'csc-dd-added' : '' }}"
                                     style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;font-size:13px;cursor:pointer;border-top:1px solid rgba(19,34,56,.05);">
                                    <span>{{ $gw }}</span>
                                    @if(in_array($gw, $client->allowed_payment_gateways ?? []))<span style="color:#15803d;font-weight:700;">&#x2713;</span>@endif
                                </div>
                            @empty
                                <div style="padding:10px 12px;font-size:13px;color:#6b7c93;">No gateways available</div>
                            @endforelse
                        </div>
                    </div>
                </div>
                <form id="csc-gw-form" method="POST"
                      action="{{ route('inbound.clients.update-gateways', $client->pms_client_id) }}" style="display:none;">
                    @csrf<div id="csc-gw-inputs"></div>
                </form>
            </div>

            @endif {{-- showGateways --}}

            {{-- Gateway Credentials (compact mode only — shown in Gateways tab) --}}
            @if($compact && $showGateways)
            @php
                $gwCreds = (array) ($client->gateway_credentials ?? []);
                $activeGateways = array_map('strtolower', $client->allowed_payment_gateways ?? []);
                $credDefs = [
                    'fluidpay' => [
                        ['key' => 'api_key',     'label' => 'Private Key',   'type' => 'password'],
                        ['key' => 'public_key',  'label' => 'Public Key',    'type' => 'text'],
                    ],
                    'paya' => [
                        ['key' => 'username',    'label' => 'Vault Username','type' => 'text'],
                        ['key' => 'password',    'label' => 'Vault Password','type' => 'password'],
                        ['key' => 'terminal_id', 'label' => 'Terminal ID',   'type' => 'text'],
                    ],
                    'nmi' => [
                        ['key' => 'security_key','label' => 'Security Key',  'type' => 'password'],
                        ['key' => 'public_key',  'label' => 'Public Key',    'type' => 'text'],
                    ],
                ];
                $commonEnv = $gwCreds['environment'] ?? '';
            @endphp
            @if(count($activeGateways))
            <div class="cc-card">
                <div style="display:flex;align-items:center;margin-bottom:6px;">
                    <div class="cc-card-title">Gateway Credentials</div>
                    <span class="csc-tip-wrap" style="margin-left:6px;">
                        <span class="csc-tip-icon">i</span>
                        <span class="csc-tip-box">Enter merchant-specific credentials. If left blank, the system uses the shared default credentials. Only non-empty fields are saved.</span>
                    </span>
                </div>
                <div class="cc-card-desc">Leave blank to use shared defaults. Enter merchant credentials to process through their own account.</div>

                <form method="POST" action="{{ route('inbound.clients.update-gateway-credentials', $client->pms_client_id) }}">
                    @csrf

                    {{-- Common environment — applies to all gateways --}}
                    <div class="cc-field" style="margin-bottom:16px;">
                        <label style="font-weight:600;">Environment</label>
                        <select name="gateway_credentials[environment]"
                                style="width:100%;padding:8px 10px;border:1px solid rgba(19,34,56,.12);border-radius:8px;font:inherit;font-size:13px;">
                            <option value="sandbox"    @selected($commonEnv !== 'production')>Sandbox</option>
                            <option value="production" @selected($commonEnv === 'production')>Production</option>
                        </select>
                    </div>

                    <div style="display:grid;gap:16px;">
                        @foreach($activeGateways as $gw)
                        @if(isset($credDefs[$gw]))
                        <div style="background:#f8f9fb;border:1px solid rgba(19,34,56,.08);border-radius:10px;padding:14px;">
                            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7c93;margin-bottom:10px;">{{ strtoupper($gw) }}</div>
                            <div style="display:grid;gap:8px;">
                                @foreach($credDefs[$gw] as $field)
                                @php
                                    $isPrivate   = $field['type'] === 'password';
                                    $isConfigured = !empty($gwCreds[$gw][$field['key']] ?? null);
                                    // Never pre-fill private/secret fields — show configured state only.
                                    // Public fields (public_key, tokenizer_url) may be pre-filled.
                                    $prefillValue = $isPrivate ? '' : old("gateway_credentials.$gw.{$field['key']}", $gwCreds[$gw][$field['key']] ?? '');
                                    $placeholder  = $isPrivate && $isConfigured ? '••••••••  (configured — leave blank to keep)' : 'Leave blank to use default';
                                @endphp
                                <div class="cc-field" style="margin:0;">
                                    <label>
                                        {{ $field['label'] }}
                                        @if($isPrivate && $isConfigured)
                                            <span style="font-size:11px;font-weight:600;color:#16a34a;margin-left:6px;">✓ Configured</span>
                                        @endif
                                    </label>
                                    <input type="{{ $field['type'] }}"
                                           name="gateway_credentials[{{ $gw }}][{{ $field['key'] }}]"
                                           value="{{ $prefillValue }}"
                                           placeholder="{{ $placeholder }}"
                                           style="width:100%;padding:7px 10px;border:1px solid rgba(19,34,56,.12);border-radius:8px;font:inherit;font-size:13px;">
                                </div>
                                @endforeach
                            </div>
                        </div>
                        @endif
                        @endforeach
                    </div>
                    <button type="submit" class="button primary" style="font-size:12px;padding:7px 16px;margin-top:14px;">Save credentials</button>
                </form>
            </div>
            @endif
            @endif {{-- showGateways + compact --}}

            {{-- Company Logo --}}
            @if($showLogo)
            @if($compact)<div class="cc-card">@else<div>@endif
                <div style="display:flex;align-items:center;margin-bottom:{{ $compact ? '10' : '6' }}px;">
                    @if($compact)
                        <div class="cc-card-title">Company Logo</div>
                    @else
                        <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6b7c93;">Company Logo</span>
                    @endif
                    <span class="csc-tip-wrap">
                        <span class="csc-tip-icon">i</span>
                        <span class="csc-tip-box">The uploaded logo appears in two places: at the top of the hosted payment page your customers see, and at the top of the payment link email. Accepted formats: JPG, PNG. Max size: 2 MB. Replacing uploads a new logo immediately.</span>
                    </span>
                </div>
                @if($client->logo_path)
                    <img src="/storage/{{ $client->logo_path }}" alt="Logo"
                         style="max-height:44px;max-width:140px;object-fit:contain;border:1px solid rgba(19,34,56,.1);border-radius:7px;padding:4px;background:#fff;display:block;margin-bottom:8px;">
                @endif
                <form method="POST" action="{{ route('inbound.clients.upload-logo', $client->pms_client_id) }}"
                      enctype="multipart/form-data" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    @csrf
                    <input type="file" name="logo" accept=".jpg,.jpeg,.png" style="font-size:12px;">
                    <button type="submit" class="button primary" style="font-size:12px;padding:5px 12px;">Upload</button>
                </form>
                @if($client->logo_path)
                    <form method="POST" action="{{ route('inbound.clients.remove-logo', $client->pms_client_id) }}" style="margin-top:5px;">
                        @csrf @method('DELETE')
                        <button type="submit" class="button secondary" onclick="return confirm('Remove company logo?')"
                                style="font-size:12px;padding:4px 10px;color:#9a2f2f;">Remove</button>
                    </form>
                @endif
                @error('logo')<p style="font-size:11px;color:#9a2f2f;margin:3px 0 0;">{{ $message }}</p>@enderror
            </div>
            @endif {{-- showLogo --}}

            {{-- Processing Fee --}}
            @if($showFees)
            @if($compact)<div class="cc-card">@else<div>@endif
                <div style="display:flex;align-items:center;margin-bottom:{{ $compact ? '10' : '8' }}px;">
                    @if($compact)
                        <div class="cc-card-title">Processing Fee</div>
                    @else
                        <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6b7c93;">Processing Fee</span>
                    @endif
                    <span class="csc-tip-wrap">
                        <span class="csc-tip-icon">i</span>
                        <span class="csc-tip-box">When enabled, a processing fee is added to the invoice amount at checkout. Choose Surcharge to add the fee on top, or Cash Discount to also offer customers an offline cash/check option at the original price.</span>
                    </span>
                </div>
                <x-inbound::fee-config-form
                    :client="$client"
                    :form-action="route('inbound.clients.update-fees', $client->pms_client_id)"
                    btn-class="button primary"
                />
            </div>
            @endif {{-- showFees --}}

        @if(!$compact)
        </div>{{-- right col --}}
    </div>{{-- grid --}}
</div>{{-- panel --}}
        @endif

<script>
(function () {
    function cscCopy(btn) {
        navigator.clipboard.writeText(document.getElementById('csc-id').textContent.trim()).then(function () {
            btn.textContent = 'Copied!';
            setTimeout(function () { btn.textContent = 'Copy'; }, 2000);
        });
    }
    window.cscCopy = cscCopy;

    var cscGateways = @json(array_map('strtoupper', $client->allowed_payment_gateways ?? []));

    function cscRemoveGw(gw) {
        if (cscGateways.length <= 1) {
            alert('At least one payment gateway must remain. Add another gateway before removing this one.');
            return;
        }
        if (!confirm('Remove ' + gw + ' from allowed gateways?')) return;
        cscGateways = cscGateways.filter(function (g) { return g !== gw; });
        cscSubmitGw();
    }
    window.cscRemoveGw = cscRemoveGw;

    function cscAddGw(gw) {
        if (cscGateways.indexOf(gw) !== -1) return;
        cscGateways.push(gw);
        cscSubmitGw();
    }

    function cscSubmitGw() {
        var form = document.getElementById('csc-gw-form');
        var container = document.getElementById('csc-gw-inputs');
        container.innerHTML = '';
        cscGateways.forEach(function (gw) {
            var i = document.createElement('input');
            i.type = 'hidden'; i.name = 'allowed_payment_gateways[]'; i.value = gw;
            container.appendChild(i);
        });
        document.getElementById('csc-dd').style.display = 'none';
        form.submit();
    }

    document.getElementById('csc-add-btn').addEventListener('click', function (e) {
        e.stopPropagation();
        var dd = document.getElementById('csc-dd');
        dd.style.display = dd.style.display === 'block' ? 'none' : 'block';
    });

    document.querySelectorAll('#csc-dd [data-gw]').forEach(function (item) {
        item.addEventListener('click', function () { if (!this.classList.contains('csc-dd-added')) cscAddGw(this.dataset.gw); });
        item.addEventListener('mouseenter', function () { if (!this.classList.contains('csc-dd-added')) this.style.background = '#f1f5f9'; });
        item.addEventListener('mouseleave', function () { this.style.background = ''; });
    });

    document.addEventListener('click', function (e) {
        var wrap = document.getElementById('csc-add-wrap');
        if (wrap && !wrap.contains(e.target)) document.getElementById('csc-dd').style.display = 'none';
    });

})();
</script>
