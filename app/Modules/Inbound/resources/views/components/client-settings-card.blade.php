@props(['client', 'availableGateways' => []])

@php $surcharge = (bool) $client->fee_surcharge_enabled; @endphp

<div class="panel" style="padding:0;overflow:hidden;margin-bottom:18px;">
    <div style="display:grid;grid-template-columns:1fr 1fr;">

        {{-- ── Left: client identity ── --}}
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

        {{-- ── Right: gateways / logo / fees ── --}}
        <div style="padding:20px 22px;display:flex;flex-direction:column;gap:18px;">

            {{-- Allowed Gateways --}}
            <div>
                <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6b7c93;display:block;margin-bottom:6px;">Allowed Gateways</span>
                <div id="csc-gw-list" style="display:flex;align-items:center;flex-wrap:wrap;gap:5px;">
                    @forelse($client->allowed_payment_gateways ?? [] as $gw)
                        <span data-gw="{{ strtoupper($gw) }}"
                              style="display:inline-flex;align-items:center;gap:3px;padding:3px 8px 3px 10px;border-radius:999px;font-size:11px;font-weight:600;background:#e9eef5;color:#132238;border:1px solid rgba(19,34,56,.12);">
                            {{ strtoupper($gw) }}
                            <button type="button" onclick="cscRemoveGw('{{ strtoupper($gw) }}')"
                                    style="background:none;border:none;cursor:pointer;color:#6b7c93;font-size:12px;line-height:1;padding:0;font-weight:700;">&#x00D7;</button>
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
                                    @if(in_array($gw, $client->allowed_payment_gateways ?? []))<span style="color:#15643b;font-weight:700;">&#x2713;</span>@endif
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

            {{-- Company Logo --}}
            <div>
                <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6b7c93;display:block;margin-bottom:6px;">Company Logo</span>
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

            {{-- Processing Fee --}}
            <div>
                <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6b7c93;display:block;margin-bottom:6px;">Processing Fee</span>
                <form method="POST" action="{{ route('inbound.clients.update-fees', $client->pms_client_id) }}">
                    @csrf
                    <input type="hidden" name="fee_surcharge_enabled" id="csc-surcharge-val" value="{{ $surcharge ? '1' : '0' }}">
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:7px 11px;background:#f8f9fb;border:1px solid rgba(19,34,56,.08);border-radius:10px;margin-bottom:10px;">
                        <span style="font-size:13px;font-weight:500;color:#132238;">Enable Surcharge</span>
                        <div style="display:flex;border:1px solid rgba(19,34,56,.15);border-radius:7px;overflow:hidden;font-size:11px;font-weight:700;">
                            <button type="button" id="csc-ton" onclick="cscSetSurcharge(true)"
                                    style="padding:4px 12px;border:none;cursor:pointer;font-family:inherit;{{ $surcharge ? 'background:#132238;color:#fff;' : 'background:#fff;color:#9ca3af;' }}">ON</button>
                            <button type="button" id="csc-toff" onclick="cscSetSurcharge(false)"
                                    style="padding:4px 12px;border:none;border-left:1px solid rgba(19,34,56,.15);cursor:pointer;font-family:inherit;{{ $surcharge ? 'background:#fff;color:#9ca3af;' : 'background:#f1f5f9;color:#374151;' }}">OFF</button>
                        </div>
                    </div>
                    <div id="csc-fee-fields" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;{{ $surcharge ? '' : 'opacity:0.4;pointer-events:none;' }}">
                        <div>
                            <label style="display:block;font-size:11px;color:#6b7c93;margin-bottom:3px;">CC Fee (%)</label>
                            <input type="number" name="cc_fee_percent" step="0.01" min="0" max="10" placeholder="3.50"
                                   value="{{ old('cc_fee_percent', $client->cc_fee_percent) }}" {{ $surcharge ? '' : 'disabled' }}
                                   style="width:86px;padding:6px 8px;border:1px solid rgba(19,34,56,.12);border-radius:9px;font:inherit;font-size:13px;">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;color:#6b7c93;margin-bottom:3px;">ACH Fee (%)</label>
                            <input type="number" name="ach_fee_percent" step="0.01" min="0" max="10" placeholder="0.50"
                                   value="{{ old('ach_fee_percent', $client->ach_fee_percent) }}" {{ $surcharge ? '' : 'disabled' }}
                                   style="width:86px;padding:6px 8px;border:1px solid rgba(19,34,56,.12);border-radius:9px;font:inherit;font-size:13px;">
                        </div>
                    </div>
                    <button type="submit" class="button primary" style="font-size:12px;padding:6px 14px;">Save fees</button>
                    @error('cc_fee_percent')<p style="font-size:11px;color:#9a2f2f;margin:3px 0 0;">{{ $message }}</p>@enderror
                    @error('ach_fee_percent')<p style="font-size:11px;color:#9a2f2f;margin:3px 0 0;">{{ $message }}</p>@enderror
                </form>
            </div>

        </div>
    </div>
</div>

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

    function cscSetSurcharge(on) {
        document.getElementById('csc-surcharge-val').value = on ? '1' : '0';
        var fields = document.getElementById('csc-fee-fields');
        fields.style.opacity = on ? '1' : '0.4';
        fields.style.pointerEvents = on ? '' : 'none';
        fields.querySelectorAll('input[type=number]').forEach(function (el) { el.disabled = !on; });
        document.getElementById('csc-ton').style.background  = on ? '#132238' : '#fff';
        document.getElementById('csc-ton').style.color       = on ? '#fff'    : '#9ca3af';
        document.getElementById('csc-toff').style.background = on ? '#fff'    : '#f1f5f9';
        document.getElementById('csc-toff').style.color      = on ? '#9ca3af' : '#374151';
    }
    window.cscSetSurcharge = cscSetSurcharge;
})();
</script>
