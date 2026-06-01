<x-inbound::layouts.master :title="($providerLabel ?? 'PMS') . ' Integration'">
    <div class="shell">
        <section class="panel">
            <p class="eyebrow">Client Setup</p>
            <h2>{{ $heading }}</h2>
            <p class="copy">{{ $copy }}</p>

            @if ($success)
                <div class="notice success">{{ $success }}</div>
            @endif

            @if ($error)
                <div class="notice error">{{ $error }}</div>
            @endif

            <div class="summary-grid">
                <div class="summary-card">
                    <span>Selected client</span>
                    <strong>{{ $client?->client_name ?? 'No client selected' }}</strong>
                </div>
                <!-- <div class="summary-card">
                    <span>PMS client id</span>
                    <strong>{{ $client?->pms_client_id ?? '--' }}</strong>
                </div>
                <div class="summary-card">
                    <span>{{ $providerLabel }} webhook URL</span>
                    <strong>{{ $webhook_url ?? '--' }}</strong>
                </div> -->
                @if (! empty($organization_name) || ! empty($organization_id))
                    <div class="summary-card">
                        <span>Zoho organization</span>
                        <strong>{{ $organization_name ?? '--' }}</strong>
                        <span>{{ $organization_id ?? '--' }}</span>
                    </div>
                @endif
            </div>

            @if ($client)
            <div class="summary-card" style="margin-top:18px;">
                <span>Company Logo</span>
                <div style="margin-top:8px;">
                    @if($client->logo_path)
                        <div style="margin-bottom:10px;">
                            <img src="/storage/{{ $client->logo_path }}"
                                 alt="Company logo"
                                 style="max-height:56px;max-width:200px;object-fit:contain;border:1px solid #e5e7eb;border-radius:6px;padding:4px;background:#fff;">
                        </div>
                    @endif
                    <form method="POST"
                          action="{{ route('inbound.clients.upload-logo', $client->pms_client_id) }}"
                          enctype="multipart/form-data"
                          style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        @csrf
                        <input type="file" name="logo" accept=".jpg,.jpeg,.png"
                               style="font-size:13px;">
                        <button type="submit" class="button primary" style="font-size:13px;padding:6px 14px;">Upload</button>
                    </form>
                    @error('logo')
                        <p style="font-size:12px;color:#dc2626;margin:6px 0 0;">{{ $message }}</p>
                    @enderror
                    @if($client->logo_path)
                        <form method="POST"
                              action="{{ route('inbound.clients.remove-logo', $client->pms_client_id) }}"
                              style="margin-top:6px;">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="button secondary"
                                    onclick="return confirm('Remove the company logo?')"
                                    style="font-size:12px;padding:4px 12px;color:#991b1b;border-color:#fca5a5;">
                                Remove logo
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="summary-card" style="margin-top:18px;">
                <span>Processing Fee Configuration</span>
                <form method="POST"
                      action="{{ route('inbound.clients.update-fees', $client->pms_client_id) }}"
                      id="fee-form-pms"
                      style="margin-top:10px;">
                    @csrf
                    <input type="hidden" name="fee_surcharge_enabled" id="fee-surcharge-val-pms"
                           value="{{ $client->fee_surcharge_enabled ? '1' : '0' }}">

                    {{-- Enable Fee Surcharge toggle --}}
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:12px;">
                        <span style="font-size:14px;font-weight:500;color:#374151;">Enable Fee Surcharge</span>
                        <div style="display:flex;border:1px solid #d1d5db;border-radius:8px;overflow:hidden;font-size:13px;font-weight:600;">
                            <button type="button" id="fee-on-pms"
                                    onclick="setFeeSurcharge('pms', true)"
                                    style="padding:6px 18px;border:none;cursor:pointer;transition:all .15s;
                                           {{ $client->fee_surcharge_enabled ? 'background:#2563eb;color:#fff;' : 'background:#fff;color:#6b7280;' }}">
                                ON
                            </button>
                            <button type="button" id="fee-off-pms"
                                    onclick="setFeeSurcharge('pms', false)"
                                    style="padding:6px 18px;border:none;border-left:1px solid #d1d5db;cursor:pointer;transition:all .15s;
                                           {{ $client->fee_surcharge_enabled ? 'background:#fff;color:#6b7280;' : 'background:#f3f4f6;color:#374151;' }}">
                                OFF
                            </button>
                        </div>
                    </div>

                    {{-- Fee inputs (disabled when surcharge is OFF) --}}
                    <div id="fee-inputs-pms" style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;
                         {{ $client->fee_surcharge_enabled ? '' : 'opacity:0.45;pointer-events:none;' }}">
                        <div>
                            <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;">CC Fee (%)</label>
                            <input type="number" name="cc_fee_percent" step="0.01" min="0" max="10"
                                   value="{{ old('cc_fee_percent', $client->cc_fee_percent) }}"
                                   placeholder="e.g. 3.50"
                                   {{ $client->fee_surcharge_enabled ? '' : 'disabled' }}
                                   style="width:110px;padding:7px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;">ACH Fee (%)</label>
                            <input type="number" name="ach_fee_percent" step="0.01" min="0" max="10"
                                   value="{{ old('ach_fee_percent', $client->ach_fee_percent) }}"
                                   placeholder="e.g. 0.50"
                                   {{ $client->fee_surcharge_enabled ? '' : 'disabled' }}
                                   style="width:110px;padding:7px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;">
                        </div>
                    </div>

                    <button type="submit" class="button primary" style="font-size:13px;padding:7px 16px;">Save fees</button>
                    @error('cc_fee_percent') <p style="font-size:12px;color:#dc2626;margin:6px 0 0;">{{ $message }}</p> @enderror
                    @error('ach_fee_percent') <p style="font-size:12px;color:#dc2626;margin:6px 0 0;">{{ $message }}</p> @enderror
                </form>
            </div>

            <style>
                .gw-badge-pms { display:inline-flex; align-items:center; gap:4px; padding:3px 8px 3px 10px; border-radius:999px; font-size:12px; font-weight:600; background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; }
                .gw-remove-pms { background:none; border:none; cursor:pointer; color:#93c5fd; font-size:14px; line-height:1; padding:0; font-weight:700; transition:color .15s; }
                .gw-remove-pms:hover { color:#1d4ed8; }
                .add-gw-wrap-pms { position:relative; display:inline-block; }
                .add-gw-pill-pms { display:inline-flex; align-items:center; gap:3px; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:600; background:#f9fafb; color:#6b7280; border:1px dashed #d1d5db; cursor:pointer; transition:all .15s; }
                .add-gw-pill-pms:hover { background:#eff6ff; color:#2563eb; border-color:#93c5fd; }
                .gw-dd-pms { display:none; position:absolute; top:calc(100% + 6px); left:0; min-width:190px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 4px 16px rgba(0,0,0,.1); z-index:200; overflow:hidden; }
                .gw-dd-pms.open { display:block; }
                .gw-dd-hdr { padding:7px 12px 5px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#9ca3af; background:#f9fafb; border-bottom:1px solid #f3f4f6; }
                .gw-dd-item { display:flex; align-items:center; justify-content:space-between; padding:9px 12px; font-size:13px; font-weight:500; color:#374151; cursor:pointer; transition:background .12s; border-top:1px solid #f3f4f6; }
                .gw-dd-item:first-of-type { border-top:none; }
                .gw-dd-item:hover:not(.gw-dd-added) { background:#eff6ff; color:#2563eb; }
                .gw-dd-item.gw-dd-added { color:#9ca3af; cursor:default; }
                .gw-dd-check { color:#10b981; font-size:14px; font-weight:700; }
            </style>

            <div class="summary-card" style="margin-top:18px;">
                <span>Allowed Payment Gateways</span>
                <div id="gw-badge-list-pms" style="margin-top:8px;display:flex;align-items:center;flex-wrap:wrap;gap:6px;">
                    @forelse($client->allowed_payment_gateways ?? [] as $gw)
                        <span class="gw-badge-pms" data-gw="{{ strtoupper($gw) }}">
                            {{ strtoupper($gw) }}
                            <button type="button" class="gw-remove-pms" onclick="pmsRemoveGateway('{{ strtoupper($gw) }}')" title="Remove {{ strtoupper($gw) }}">&#x00D7;</button>
                        </span>
                    @empty
                        <span id="gw-empty-pms" style="font-size:13px;color:#9ca3af;">None configured</span>
                    @endforelse

                    <div class="add-gw-wrap-pms" id="add-gw-wrap-pms">
                        <button type="button" class="add-gw-pill-pms" id="add-gw-toggle-pms">+ Add</button>
                        <div class="gw-dd-pms" id="gw-dd-pms">
                            <div class="gw-dd-hdr">Available gateways</div>
                            @forelse($availableGateways as $gw)
                                <div class="gw-dd-item {{ in_array($gw, $client->allowed_payment_gateways ?? []) ? 'gw-dd-added' : '' }}"
                                     data-gw="{{ $gw }}">
                                    <span>{{ $gw }}</span>
                                    @if(in_array($gw, $client->allowed_payment_gateways ?? []))
                                        <span class="gw-dd-check">&#x2713;</span>
                                    @endif
                                </div>
                            @empty
                                <div style="padding:10px 12px;font-size:13px;color:#9ca3af;">No gateways available</div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <form id="gw-update-form-pms" method="POST"
                      action="{{ route('inbound.clients.update-gateways', $client->pms_client_id) }}"
                      style="display:none;">
                    @csrf
                    <div id="gw-hidden-inputs-pms"></div>
                </form>
            </div>

            <script>
                let pmsGateways = @json(array_map('strtoupper', $client->allowed_payment_gateways ?? []));

                function pmsRenderBadges() {
                    const list = document.getElementById('gw-badge-list-pms');
                    const wrap = document.getElementById('add-gw-wrap-pms');
                    list.querySelectorAll('.gw-badge-pms').forEach(b => b.remove());
                    const emptyEl = document.getElementById('gw-empty-pms');
                    if (emptyEl) emptyEl.remove();

                    if (pmsGateways.length === 0) {
                        const empty = document.createElement('span');
                        empty.id = 'gw-empty-pms';
                        empty.style.cssText = 'font-size:13px;color:#9ca3af;';
                        empty.textContent = 'None configured';
                        list.insertBefore(empty, wrap);
                    } else {
                        [...pmsGateways].reverse().forEach(gw => {
                            const badge = document.createElement('span');
                            badge.className = 'gw-badge-pms';
                            badge.dataset.gw = gw;
                            badge.innerHTML = gw + ' <button type="button" class="gw-remove-pms" onclick="pmsRemoveGateway(\'' + gw + '\')" title="Remove ' + gw + '">&#x00D7;</button>';
                            list.insertBefore(badge, list.firstChild);
                        });
                    }

                    document.querySelectorAll('.gw-dd-item').forEach(item => {
                        const added = pmsGateways.includes(item.dataset.gw);
                        item.classList.toggle('gw-dd-added', added);
                        let check = item.querySelector('.gw-dd-check');
                        if (added && !check) {
                            check = document.createElement('span');
                            check.className = 'gw-dd-check';
                            check.innerHTML = '&#x2713;';
                            item.appendChild(check);
                        } else if (!added && check) {
                            check.remove();
                        }
                    });
                }

                function pmsAddGateway(gw) {
                    if (pmsGateways.includes(gw)) return;
                    pmsGateways.push(gw);
                    pmsSubmitGateways();
                }

                function pmsRemoveGateway(gw) {
                    if (!confirm('Remove ' + gw + ' from allowed gateways?')) return;
                    pmsGateways = pmsGateways.filter(g => g !== gw);
                    pmsSubmitGateways();
                }

                function pmsSubmitGateways() {
                    const form      = document.getElementById('gw-update-form-pms');
                    const container = document.getElementById('gw-hidden-inputs-pms');
                    container.innerHTML = '';
                    pmsGateways.forEach(gw => {
                        const input = document.createElement('input');
                        input.type  = 'hidden';
                        input.name  = 'allowed_payment_gateways[]';
                        input.value = gw;
                        container.appendChild(input);
                    });
                    document.getElementById('gw-dd-pms').classList.remove('open');
                    form.submit();
                }

                document.getElementById('add-gw-toggle-pms').addEventListener('click', function (e) {
                    e.stopPropagation();
                    document.getElementById('gw-dd-pms').classList.toggle('open');
                });

                document.querySelectorAll('.gw-dd-item').forEach(item => {
                    item.addEventListener('click', function () {
                        if (!this.classList.contains('gw-dd-added')) {
                            pmsAddGateway(this.dataset.gw);
                        }
                    });
                });

                document.addEventListener('click', function (e) {
                    const wrap = document.getElementById('add-gw-wrap-pms');
                    if (wrap && !wrap.contains(e.target)) {
                        document.getElementById('gw-dd-pms').classList.remove('open');
                    }
                });

                function setFeeSurcharge(ns, enabled) {
                    document.getElementById('fee-surcharge-val-' + ns).value = enabled ? '1' : '0';
                    const inputs = document.getElementById('fee-inputs-' + ns);
                    inputs.style.opacity = enabled ? '1' : '0.45';
                    inputs.style.pointerEvents = enabled ? '' : 'none';
                    inputs.querySelectorAll('input[type=number]').forEach(function(el) { el.disabled = !enabled; });
                    document.getElementById('fee-on-' + ns).style.background  = enabled ? '#2563eb' : '#fff';
                    document.getElementById('fee-on-' + ns).style.color       = enabled ? '#fff' : '#6b7280';
                    document.getElementById('fee-off-' + ns).style.background = enabled ? '#fff' : '#f3f4f6';
                    document.getElementById('fee-off-' + ns).style.color      = enabled ? '#6b7280' : '#374151';
                }
            </script>
            @endif

            <div class="actions">
                <!-- <a class="button secondary" href="{{ route('inbound.clients.create') }}">Create New Client</a> -->
                @if ($connectUrl)
                    <a class="button primary" href="{{ $connectUrl }}">Connect {{ $providerLabel }}</a>
                @endif
            </div>

            @if ($shareUrl && ! $openedViaShareLink && ( empty($organization_name) ||  empty($organization_id)) && ! ($provider === 'wave' && $connection))
                <div class="summary-card" style="margin-top: 18px;">
                    <span>Share this setup link with client</span>
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 8px;">
                        <input id="setup-share-url" type="text" value="{{ $shareUrl }}" readonly style="flex: 1; min-width: 280px; padding: 10px 12px; border: 1px solid rgba(19, 34, 56, 0.12); border-radius: 12px; font: inherit;">
                        <button type="button" class="button secondary" onclick="copyShareLink()">Copy Link</button>
                    </div>
                </div>
                <script>
                    function copyShareLink() {
                        const input = document.getElementById('setup-share-url');
                        input.select();
                        input.setSelectionRange(0, 99999);
                        navigator.clipboard.writeText(input.value);
                    }
                </script>
            @endif

            @if ($provider === 'quickbooks' && $connection)
                <div class="summary-card" style="margin-top: 18px;">
                    <span>QuickBooks Company (Realm) ID</span>
                    <strong>{{ $realm_id ?: 'Not connected yet' }}</strong>
                </div>

                @if ($client)
                    <div class="summary-card" style="margin-top: 18px;">
                        <span>Default deposit account</span>
                        <strong>{{ $client->qb_default_account_name ?: 'Not selected' }}</strong>
                        <span>{{ $client->qb_default_account_id ?: '--' }}</span>

                        @if (! empty($qb_account_load_error))
                            <div class="notice error" style="margin-top: 8px;">{{ $qb_account_load_error }}</div>
                        @endif

                        @if (! empty($qb_accounts))
                            <form method="POST" action="{{ route('inbound.quickbooks.default-account') }}" class="form-grid" style="margin-top: 10px;">
                                @csrf
                                <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                                <label class="field">
                                    <span>Default deposit account (chart of accounts)</span>
                                    <select name="qb_default_account_id" required>
                                        <option value="">Select account</option>
                                        @foreach ($qb_accounts as $account)
                                            <option
                                                value="{{ $account['account_id'] }}"
                                                @selected((string) $client->qb_default_account_id === (string) $account['account_id'])
                                            >
                                                {{ $account['account_name'] }}{{ $account['account_type'] ? ' ('.$account['account_type'].')' : '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                                <button type="submit" class="button primary">Save default account</button>
                            </form>
                        @elseif (empty($qb_account_load_error))
                            <div class="notice error" style="margin-top: 8px;">
                                No accounts returned from QuickBooks chart of accounts. Reconnect QuickBooks or verify the connected user has access.
                            </div>
                        @endif
                    </div>
                @endif
            @endif

            @if ($provider === 'clio' && $client && $connection)
                <div class="summary-card" style="margin-top: 18px;">
                    <span>Default Clio bank account</span>
                    <strong>{{ $client->clio_default_bank_account_name ?: 'Not selected' }}</strong>
                    <span>{{ $client->clio_default_bank_account_id ?: '--' }}</span>

                    @if (! empty($clio_bank_account_load_error))
                        <div class="notice error" style="margin-top: 8px;">{{ $clio_bank_account_load_error }}</div>
                    @endif

                    @if (! empty($clio_bank_accounts))
                        <form method="POST" action="{{ route('inbound.clio.default-bank-account') }}" class="form-grid" style="margin-top: 10px;">
                            @csrf
                            <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                            <label class="field">
                                <span>Default Clio bank account</span>
                                <select name="clio_default_bank_account_id" required>
                                    <option value="">Select account</option>
                                    @foreach ($clio_bank_accounts as $account)
                                        <option
                                            value="{{ $account['account_id'] }}"
                                            @selected((string) $client->clio_default_bank_account_id === (string) $account['account_id'])
                                        >
                                            {{ $account['account_name'] }}{{ $account['account_type'] ? ' ('.$account['account_type'].')' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                            <button type="submit" class="button primary">Save default account</button>
                        </form>
                    @else
                        <div class="notice error" style="margin-top: 8px;">
                            No Clio bank accounts were returned. Reconnect Clio or verify the connected user has access to bank accounts.
                        </div>
                    @endif
                </div>
            @endif

            @if ($provider === 'lawcus' && $client && $connection)
                <div class="summary-card" style="margin-top: 18px;">
                    <span>Default Lawcus bank account</span>
                    <strong>{{ $client->lawcus_default_bank_account_name ?: 'Not selected' }}</strong>
                    <span>{{ $client->lawcus_default_bank_account_id ?: '--' }}</span>

                    @if (! empty($lawcus_bank_account_load_error))
                        <div class="notice error" style="margin-top: 8px;">{{ $lawcus_bank_account_load_error }}</div>
                    @endif

                    @if (! empty($lawcus_bank_accounts))
                        <form method="POST" action="{{ route('inbound.lawcus.default-bank-account') }}" class="form-grid" style="margin-top: 10px;">
                            @csrf
                            <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                            <label class="field">
                                <span>Default Lawcus bank account</span>
                                <select name="lawcus_default_bank_account_id" required>
                                    <option value="">Select account</option>
                                    @foreach ($lawcus_bank_accounts as $account)
                                        <option
                                            value="{{ $account['account_id'] }}"
                                            @selected((string) $client->lawcus_default_bank_account_id === (string) $account['account_id'])
                                        >
                                            {{ $account['account_name'] }}{{ $account['account_type'] ? ' ('.$account['account_type'].')' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                            <button type="submit" class="button primary">Save default account</button>
                        </form>
                    @else
                        <div class="notice error" style="margin-top: 8px;">
                            No Lawcus bank accounts were returned. Reconnect Lawcus or verify the connected user has access to bank accounts.
                        </div>
                    @endif
                </div>
            @endif

            @if ($provider === 'wave' && $client && $connection)
                <div class="summary-card" style="margin-top: 18px;">
                    <span>Default Wave payment account</span>
                    <strong>{{ $client->wave_default_account_name ?: 'Not selected' }}</strong>
                    <span>{{ $client->wave_default_account_id ?: '--' }}</span>

                    @if (! empty($wave_account_load_error))
                        <div class="notice error" style="margin-top: 8px;">{{ $wave_account_load_error }}</div>
                    @endif

                    @if (! empty($wave_payment_accounts))
                        <form method="POST" action="{{ route('inbound.wave.default-account') }}" class="form-grid" style="margin-top: 10px;">
                            @csrf
                            <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                            <label class="field">
                                <span>Default payment account</span>
                                <select name="wave_default_account_id" required>
                                    <option value="">Select account</option>
                                    @foreach ($wave_payment_accounts as $account)
                                        <option
                                            value="{{ $account['account_id'] }}"
                                            @selected((string) $client->wave_default_account_id === (string) $account['account_id'])
                                        >
                                            {{ $account['account_name'] }}{{ $account['account_type'] ? ' ('.$account['account_type'].')' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                            <button type="submit" class="button primary">Save default account</button>
                        </form>
                    @elseif (empty($wave_account_load_error))
                        <div class="notice error" style="margin-top: 8px;">
                            No Wave accounts were returned. Reconnect Wave or verify the connected user has access to accounts.
                        </div>
                    @endif
                </div>
            @endif

            @if ($provider === 'wave')
                <div style="margin-top: 24px; padding: 20px 24px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; font-family: sans-serif;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 18px;">
                        <span style="font-size: 22px;">&#9881;</span>
                        <strong style="font-size: 16px; color: #111827;">How Wave integration works</strong>
                    </div>

                    {{-- Demo video --}}
                    <div style="margin-bottom: 20px;">
                        <div style="position: relative; padding-bottom: 56.25%; height: 0; border-radius: 10px; overflow: hidden; border: 1px solid #e5e7eb;">
                            <iframe
                                src="https://www.loom.com/embed/22e129965ac649dcbe440078e3b4cbed"
                                frameborder="0"
                                allowfullscreen
                                style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;">
                            </iframe>
                        </div>
                    </div>

                    {{-- Flow overview --}}
                    <div style="margin-bottom: 20px; padding: 14px 16px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; font-size: 14px; color: #1e40af; line-height: 1.7;">
                        <strong style="display: block; margin-bottom: 6px; color: #1d4ed8;">End-to-end payment flow</strong>
                        <ol style="margin: 0 0 0 18px; padding: 0;">
                            <li>You approve an invoice in Wave &rarr; Wave fires an <code style="background:#dbeafe;padding:1px 5px;border-radius:3px;">invoice.approved</code> webhook to this middleware.</li>
                            <li>The middleware fetches the invoice details via the Wave GraphQL API and creates a payment session.</li>
                            <li>A payment link is emailed to the customer. The customer clicks the link and completes payment.</li>
                            <li>The middleware records the payment in Wave — the invoice is marked <strong>Paid</strong> automatically in your Wave account.</li>
                        </ol>
                    </div>

                    {{-- Setup steps --}}
                    <strong style="display: block; margin-bottom: 10px; font-size: 14px; color: #374151;">Setup steps</strong>
                    <ol style="margin: 0 0 20px 20px; padding: 0; color: #374151; font-size: 14px; line-height: 1.8;">
                        <li><strong>Connect Wave</strong> — Click the <em>Connect Wave</em> button above and authorise this middleware with your Wave account. This stores a secure OAuth token so the middleware can read invoices and record payments on your behalf.</li>
                        <li><strong>Select a default payment account</strong> — After connecting, choose the Wave account (e.g. <em>Chequing</em> or <em>Stripe Payments</em>) where collected payments should be deposited. This is used as the deposit account when marking invoices paid.</li>
                    </ol>

                    {{-- Event trigger info --}}
                    <div style="margin-bottom: 16px; padding: 12px 16px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; color: #374151;">
                        <strong style="display: block; margin-bottom: 4px;">Which invoices trigger the payment flow?</strong>
                        <p style="margin: 0;">The payment flow is triggered whenever you <strong>approve an invoice</strong> in Wave. Wave fires an <code style="background:#f3f4f6;padding:1px 5px;border-radius:3px;">invoice.approved</code> event to this middleware — no manual webhook setup needed, that is handled automatically on the backend.</p>
                    </div>

                    {{-- Payment effect --}}
                    <div style="padding: 12px 16px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; color: #374151;">
                        <strong style="display: block; margin-bottom: 4px;">How payments appear in Wave</strong>
                        <p style="margin: 0;">Once the customer pays, the middleware calls Wave's <code style="background:#f3f4f6;padding:1px 5px;border-radius:3px;">invoicePaymentCreateManual</code> API. This records a payment transaction against the invoice and changes its status to <strong>Paid</strong> — no manual entry required in Wave.</p>
                    </div>
                </div>

            @endif

            @if ($provider === 'zoho' && $client && $connection)
                <div class="summary-card" style="margin-top: 18px;">
                    <span>Default Zoho deposit account</span>
                    <strong>{{ $client->zoho_default_account_name ?: 'Not selected' }}</strong>
                    <span>{{ $client->zoho_default_account_id ?: '--' }}</span>

                    @if (! empty($zoho_account_load_error))
                        <div class="notice error" style="margin-top: 8px;">{{ $zoho_account_load_error }}</div>
                    @endif

                    @if (! empty($zoho_payment_accounts))
                        <form method="POST" action="{{ route('inbound.zoho.default-account') }}" class="form-grid" style="margin-top: 10px;">
                            @csrf
                            <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                            <label class="field">
                                <span>Default Zoho deposit account</span>
                                <select id="zoho-default-account-select" name="zoho_default_account_id" required>
                                    <option value="">Select account</option>
                                    @foreach ($zoho_payment_accounts as $account)
                                        <option
                                            value="{{ $account['account_id'] }}"
                                            @selected((string) $client->zoho_default_account_id === (string) $account['account_id'])
                                        >
                                            {{ $account['account_name'] }} ({{ $account['account_type'] ?: 'Account' }})
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                            <button type="submit" class="button primary">Save default account</button>
                        </form>
                    @else
                        <div class="notice error" style="margin-top: 8px;">
                            No Zoho accounts were returned for this organization. Reconnect Zoho or verify the connected user has access to chart of accounts.
                        </div>
                    @endif
                </div>
            @endif

            @if ($provider === 'zoho' && $connection)
                @if (($webhook_auto_setup_status ?? null) === 'success')
                    <div style="margin-top: 18px; padding: 16px 20px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; display: flex; align-items: flex-start; gap: 12px;">
                        <span style="font-size: 20px; flex-shrink: 0;">&#10003;</span>
                        <div>
                            <strong style="color: #15803d; display: block; margin-bottom: 4px;">Webhook configured automatically</strong>
                            <span style="color: #166534; font-size: 14px;">
                                Zoho Books has been configured to notify this middleware when invoices are created.
                                @if (!empty($webhook_id))
                                    Webhook ID: <code style="background: #dcfce7; padding: 1px 5px; border-radius: 3px;">{{ $webhook_id }}</code>
                                @endif
                                @if (!empty($workflow_id))
                                    &nbsp;&middot;&nbsp; Workflow ID: <code style="background: #dcfce7; padding: 1px 5px; border-radius: 3px;">{{ $workflow_id }}</code>
                                @endif
                            </span>
                        </div>
                    </div>
                @elseif (($webhook_auto_setup_status ?? null) === 'failed')
                    <div style="margin-top: 18px; padding: 16px 20px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px;">
                        <strong style="color: #dc2626; display: block; margin-bottom: 4px;">Automatic webhook setup failed</strong>
                        @if (!empty($webhook_auto_setup_error))
                            <p style="color: #991b1b; font-size: 14px; margin: 0 0 8px;">{{ $webhook_auto_setup_error }}</p>
                        @endif
                        <p style="color: #7f1d1d; font-size: 13px; margin: 0;">Please configure the webhook manually using the instructions below, or reconnect Zoho to retry.</p>
                    </div>
                @endif
            @endif

            @if (!empty($webhook_instructions))
            <div class="webhook-card" style="margin-top: 18px; padding: 20px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; font-family: sans-serif;">
                <div style="display: flex; align-items: center; margin-bottom: 15px;">
                    <span style="font-size: 20px; margin-right: 8px;">&#128279;</span>
                    <strong style="font-size: 16px; color: #111827;">Zoho Books Webhook Setup Instructions</strong>
                </div>

                @if (!empty($webhook_instructions['demo_video_url']))
                    <div style="margin-bottom: 18px; padding: 14px 16px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px;">
                        <div style="font-weight: 600; color: #1d4ed8; margin-bottom: 6px;">Need a walkthrough?</div>
                        <a
                            href="{{ $webhook_instructions['demo_video_url'] }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            style="display: inline-block; padding: 10px 14px; background: #2563eb; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600;"
                        >
                            View webhook setup demo
                        </a>
                    </div>
                @endif

                <!-- Step-by-Step Guide -->
                <ol style="margin: 0 0 20px 20px; padding: 0; color: #374151; line-height: 1.6;">
                    @foreach ($webhook_instructions['steps'] as $index => $instruction)
                        <li style="margin-bottom: 10px;">{!! $instruction !!}</li>

                        {{-- Inject the detail cards after Step 3 --}}
                        @if ($index === 2)
                            <div style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 15px; margin: 15px 0; font-size: 14px;">
                                <div style="margin-bottom: 8px;"><strong>Name:</strong> Invoice Create</div>
                                <div style="margin-bottom: 12px;"><strong>Module:</strong> Invoices</div>

                                <!-- URL Field -->
                                <div style="margin-bottom: 12px;">
                                    <label style="font-weight: 600; display: block; margin-bottom: 4px;">URL to Notify:</label>
                                    <div style="display: flex; gap: 8px;">
                                        <input type="text" id="webhook-url-input" value="{{ $webhook_instructions['url'] }}" readonly style="flex: 1; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; background: #f3f4f6; color: #4b5563;">
                                        <button onclick="copyToClipboard('webhook-url-input', this)" style="padding: 0 12px; background: #2563eb; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: 600;">Copy</button>
                                    </div>
                                </div>

                                <!-- JSON Field -->
                                <div>
                                    <label style="font-weight: 600; display: block; margin-bottom: 4px;">Body (Choose Raw JSON and paste this):</label>
                                    <div style="position: relative;">
                                        <textarea id="webhook-json-input" rows="7" readonly style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 4px; background: #1f2937; color: #f9fafb; font-family: monospace; font-size: 13px; box-sizing: border-box;">{{ @$webhook_instructions['json'] }}</textarea>
                                        <button onclick="copyToClipboard('webhook-json-input', this)" style="position: absolute; top: 8px; right: 8px; padding: 6px 10px; background: #4b5563; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">Copy JSON</button>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </ol>
            </div>

            <!-- Clipboard JS Script -->
            <script>
                function copyToClipboard(elementId, button) {
                    const copyText = document.getElementById(elementId);
                    copyText.select();
                    copyText.setSelectionRange(0, 99999);
                    navigator.clipboard.writeText(copyText.value);

                    const originalText = button.innerText;
                    button.innerText = 'Copied!';
                    button.style.background = '#059669';

                    setTimeout(() => {
                        button.innerText = originalText;
                        button.style.background = elementId === 'webhook-json-input' ? '#4b5563' : '#2563eb';
                    }, 1500);
                }
            </script>
        @endif
        </section>

        <!-- <section class="panel list-panel">
            <p class="eyebrow">Configured {{ $providerLabel }} Clients</p>
            <div class="client-list">
                @forelse ($clients as $configuredClient)
                    <a class="client-row" href="{{ route("inbound.{$provider}.page", ['pms_client_id' => $configuredClient->pms_client_id]) }}">
                        <strong>{{ $configuredClient->client_name }}</strong>
                        <span>{{ $configuredClient->client_pms }}</span>
                        <code>{{ $configuredClient->pms_client_id }}</code>
                    </a>
                @empty
                    <p class="empty">No {{ $providerLabel }} clients configured yet.</p>
                @endforelse
            </div>
        </section> -->
    </div>
</x-inbound::layouts.master>
