@php
    $pmsTabs = [
        ['id' => 'connection', 'label' => $providerLabel . ' Connection'],
        ['id' => 'gateways', 'label' => 'Gateways'],
        ['id' => 'fees', 'label' => 'Fee Configuration'],
        ['id' => 'webhooks', 'label' => 'Webhooks'],
        ['id' => 'email', 'label' => 'Email Settings'],
        ['id' => 'invoices', 'label' => 'Invoice List'],
    ];
@endphp

<style>
    .cc-preview-overlay {

        display: none;

        position: fixed;

        top: 0;
        left: 0;

        width: 100%;
        height: 100%;

        background: rgba(0, 0, 0, .45);

        z-index: 9999;

        justify-content: center;

        align-items: center;

    }

    .cc-preview-modal {

        width: 820px;

        max-width: 95%;

        max-height: 90vh;

        overflow: hidden;

        background: #fff;

        border-radius: 10px;

        box-shadow: 0 20px 60px rgba(0, 0, 0, .25);

        display: flex;

        flex-direction: column;

    }

    .cc-preview-header {

        padding: 16px 22px;

        border-bottom: 1px solid #e5e7eb;

        display: flex;

        justify-content: space-between;

        align-items: center;

    }

    .cc-preview-title {

        font-size: 18px;

        font-weight: 700;

    }

    .cc-preview-close {

        border: none;

        background: none;

        cursor: pointer;

        font-size: 22px;

    }

    .cc-preview-body {

        padding: 30px;

        overflow: auto;

        background: #f8fafc;

    }

    .cc-preview-footer {

        padding: 16px;

        border-top: 1px solid #e5e7eb;

        text-align: right;

    }

    .cc-action-bar {

        display: flex;

        justify-content: flex-end;

        align-items: center;

        gap: 12px;

        margin-top: 30px;

    }

    .button-light {

        background: #ffffff;

        color: #132238;

        border: 1px solid #d6dce5;

        display: flex;

        align-items: center;

        gap: 8px;

        padding: 10px 18px;

        border-radius: 30px;

        transition: .2s;

    }

    .button-light:hover {

        background: #f8fafc;

        border-color: #132238;

    }

    .button.primary {

        padding: 10px 24px;

        border-radius: 30px;

    }

    .cc-table {
        width: 100%;
        border-collapse: collapse;
    }

    .cc-table th,
    .cc-table td {
        padding: 10px;
        border: 1px solid #e5e7eb;
    }

    .cc-table th {
        background: #f9fafb;
        text-align: left;
    }
</style>

<x-inbound::layouts.master :title="($providerLabel ?? 'PMS') . ' Configuration'">

    @if ($client)
        <x-inbound::client-config-layout :client="$client" :provider-label="$providerLabel ?? ''" :tabs="$pmsTabs">

            {{-- ── Connection tab ── --}}
            <div id="cc-panel-connection" class="cc-tab-panel">

                @if (request()->query('success'))
                    <div class="cc-notice success" style="margin-bottom:14px;"> {{ request()->query('success') }}</div>
                @endif

                @if (request()->query('error'))
                    <div class="cc-notice error" style="margin-bottom:14px;"> {{ request()->query('error') }}</div>
                @endif

                {{-- Connected Services --}}
                <div class="cc-card">
                    <div class="cc-card-title">Connected Services</div>
                    <div class="cc-card-desc">Active PMS and payment gateway connections for this client.</div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <div class="cc-conn-badge">
                            <span class="cc-conn-dot" style="{{ $connection ? '' : 'background:#f59e0b;' }}"></span>
                            {{ $providerLabel }}
                            @if (!$connection)
                                <span style="font-weight:400;color:#92400e;"> · Not connected</span>
                            @endif
                        </div>
                        @foreach ($client->allowed_payment_gateways ?? [] as $gw)
                            @php $isPaused = in_array(strtoupper($gw), array_map('strtoupper', $client->paused_payment_gateways ?? [])); @endphp
                            <div class="cc-conn-badge" style="{{ $isPaused ? 'opacity:.5;' : '' }}">
                                <span class="cc-conn-dot" style="{{ $isPaused ? 'background:#9ca3af;' : '' }}"></span>
                                {{ strtoupper($gw) }}
                                @if ($isPaused)
                                    <span style="font-weight:400;"> · Paused</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- PMS Connection Status --}}
                @if ($connection)
                    <div class="cc-card">
                        <div
                            style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                            <div style="display:flex;align-items:center;gap:12px;">
                                <div
                                    style="width:36px;height:36px;border-radius:50%;background:#dcfce7;display:flex;align-items:center;justify-content:center;font-size:18px;color:#15803d;flex-shrink:0;">
                                    &#10003;</div>
                                <div>
                                    <div class="cc-card-title" style="color:#15803d;">{{ $providerLabel }} Connected
                                    </div>
                                    <div style="font-size:12px;color:#6b7280;margin-top:1px;">Connected
                                        {{ $connection->created_at->diffForHumans() }}</div>
                                </div>
                            </div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">

                                @if ($connectUrl)
                                    {{-- <a href="{{ $connectUrl }}" class="reconnect-btn"
                                        style="font-size:12px;padding:7px 16px;">Reconnect</a> --}}

                                    <a href="{{ $connectUrl }}" class="button secondary"
                                        style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;font-size:12px;padding:7px 16px;display:inline-block;text-decoration:none;line-height:1.2;vertical-align:middle;box-sizing:border-box;">
                                        Reconnect
                                    </a>
                                @endif
                                @if ($provider === 'quickbooks' && $client)
                                    <form method="POST" action="{{ route('inbound.quickbooks.disconnect') }}"
                                        onsubmit="return confirm('Disconnect QuickBooks? The client will need to reconnect to resume invoice processing.');">
                                        @csrf
                                        <input type="hidden" name="pms_client_id"
                                            value="{{ $client->pms_client_id }}">
                                        {{-- <button type="submit" class="button secondary"
                                            style="font-size:12px;padding:7px 16px;color:#dc2626;border-color:#dc2626;">Disconnect</button> --}}

                                        <button type="submit" class="button secondary"
                                            style="font-size:12px;padding:7px 16px;color:#dc2626;border-color:#dc2626;background:#fef2f2;">
                                            Disconnect
                                        </button>
                                    </form>
                                @endif

                                @if ($provider === 'wave' && $client)
                                    <form method="POST" action="{{ route('inbound.wave.disconnect') }}"
                                        onsubmit="return confirm('Disconnect Wave? The client will need to reconnect to resume invoice processing.');">
                                        @csrf
                                        <input type="hidden" name="pms_client_id"
                                            value="{{ $client->pms_client_id }}">
                                        {{-- <button type="submit" class="button secondary"
                                            style="font-size:12px;padding:7px 16px;color:#dc2626;border-color:#dc2626;">Disconnect</button> --}}

                                        <button type="submit" class="button secondary"
                                            style="font-size:12px;padding:7px 16px;color:#dc2626;border-color:#dc2626;background:#fef2f2;">
                                            Disconnect
                                        </button>
                                    </form>
                                @endif

                                @if ($provider === 'clio' && $client)
                                    <form method="POST" action="{{ route('inbound.clio.disconnect') }}"
                                        onsubmit="return confirm('Disconnect Clio? The client will need to reconnect to resume invoice processing.');">
                                        @csrf
                                        <input type="hidden" name="pms_client_id"
                                            value="{{ $client->pms_client_id }}">
                                        <button type="submit" class="button secondary"
                                            style="font-size:12px;padding:7px 16px;color:#dc2626;border-color:#dc2626;background:#fef2f2;">
                                            Disconnect
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                        @php
                            $connDetails = [];
                            if (!empty($company_name)) {
                                $connDetails['Company Name'] = $company_name;
                            }
                            if (!empty($realm_id)) {
                                $connDetails['Company Realm ID'] = $realm_id;
                            }
                            if (!empty($organization_name)) {
                                $connDetails['Organization'] = $organization_name;
                            }
                            if (!empty($organization_id)) {
                                $connDetails['Organization ID'] = $organization_id;
                            }
                            if (!empty($connection_environment)) {
                                $connDetails['Environment'] = ucfirst($connection_environment);
                            }
                            if ($connection->token_expires_at) {
                                $connDetails['Token Expires'] = $connection->token_expires_at->format('M j, Y');
                            }
                        @endphp
                        @if (count($connDetails))
                            <hr class="cc-card-divider">
                            <div style="display:flex;flex-wrap:wrap;gap:20px;">
                                @foreach ($connDetails as $lbl => $val)
                                    <div>
                                        <div
                                            style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:2px;">
                                            {{ $lbl }}</div>
                                        <div
                                            style="font-size:13px;font-weight:600;color:#1a1a2e;{{ str_contains($lbl, 'ID') ? 'font-family:ui-monospace,monospace;' : '' }}">
                                            {{ $val }}</div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    @if (
                        $provider === 'quickbooks' &&
                            !empty($connection_environment) &&
                            $connection_environment !== ($configured_environment ?? 'sandbox'))
                        <div class="cc-notice error">
                            This client is configured for {{ ucfirst($configured_environment ?? 'sandbox') }}, but the
                            active QuickBooks connection is {{ ucfirst($connection_environment) }}. Disconnect and
                            reconnect QuickBooks to switch environments.
                        </div>
                    @endif
                @else
                    <div class="cc-card" style="background:#fffbeb;border-color:#fde68a;">
                        <div
                            style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                            <div style="display:flex;align-items:center;gap:12px;">
                                <span
                                    style="width:10px;height:10px;border-radius:50%;background:#f59e0b;flex-shrink:0;display:inline-block;"></span>
                                <div>
                                    <div class="cc-card-title" style="color:#92400e;">{{ $providerLabel }} not
                                        connected</div>
                                    <div style="font-size:13px;color:#78350f;margin-top:2px;">Complete the connection to
                                        start processing invoices.</div>
                                    @if ($provider === 'quickbooks')
                                        @php $connectEnv = $configured_environment ?? 'sandbox'; @endphp
                                        <div
                                            style="font-size:12px;color:#78350f;margin-top:6px;display:flex;align-items:center;gap:6px;">
                                            <span
                                                style="width:8px;height:8px;border-radius:50%;background:{{ $connectEnv === 'production' ? '#16a34a' : '#f59e0b' }};flex-shrink:0;"></span>
                                            @if (empty($configured_environment_raw ?? null))
                                                No environment selected in Gateway Credentials — connecting will default
                                                to <strong>Sandbox</strong>.
                                            @else
                                                Connecting will use the <strong>{{ ucfirst($connectEnv) }}</strong>
                                                QuickBooks environment (from this client's Gateway Credentials setting).
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                            @if ($connectUrl)
                                <a href="{{ $connectUrl }}" class="button primary">Connect {{ $providerLabel }}</a>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Default Account (QB) --}}
                @if ($provider === 'quickbooks' && $connection && $client)
                    <div class="cc-card">
                        <div class="cc-card-title">Default Deposit Account</div>
                        <div class="cc-card-desc">Chart of accounts entry where collected payments are deposited in
                            QuickBooks.</div>
                        @if ($client->qb_default_account_name)
                            <div style="font-size:13px;font-weight:600;color:#1a1a2e;margin-bottom:12px;">
                                Currently: {{ $client->qb_default_account_name }}
                            </div>
                        @endif
                        @error('qb_default_account_id')
                            <p style="font-size:12px;color:#dc2626;margin-bottom:8px;">{{ $message }}</p>
                        @enderror
                        @if (!empty($qb_account_load_error))
                            <div class="cc-notice error">{{ $qb_account_load_error }}</div>
                        @elseif(!empty($qb_accounts))
                            <form method="POST" action="{{ route('inbound.quickbooks.default-account') }}">
                                @csrf
                                <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                                <div class="cc-field">
                                    <label>Select account</label>
                                    <select name="qb_default_account_id" required>
                                        <option value="">Choose from chart of accounts…</option>
                                        @foreach ($qb_accounts as $account)
                                            <option value="{{ $account['account_id'] }}" @selected((string) $client->qb_default_account_id === (string) $account['account_id'])>
                                                {{ $account['account_name'] }}{{ $account['account_type'] ? ' (' . $account['account_type'] . ')' : '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <button type="submit" class="button primary" style="font-size:13px;">Save
                                    account</button>
                            </form>
                        @else
                            <div class="cc-notice error">No accounts returned from QuickBooks. Reconnect or verify the
                                connected user has access.</div>
                        @endif
                    </div>
                @endif

                {{-- Default Account (Clio) --}}
                @if ($provider === 'clio' && $client && $connection)
                    <div class="cc-card">
                        <div class="cc-card-title">Default Clio Bank Account</div>
                        <div class="cc-card-desc">Bank account used when recording payments back to Clio.</div>
                        @if ($client->clio_default_bank_account_name)
                            <div style="font-size:13px;font-weight:600;color:#1a1a2e;margin-bottom:12px;">Currently:
                                {{ $client->clio_default_bank_account_name }}</div>
                        @endif
                        @if (!empty($clio_bank_account_load_error))
                            <div class="cc-notice error">{{ $clio_bank_account_load_error }}</div>
                        @elseif(!empty($clio_bank_accounts))
                            <form method="POST" action="{{ route('inbound.clio.default-bank-account') }}">
                                @csrf
                                <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                                <div class="cc-field">
                                    <label>Select bank account</label>
                                    <select name="clio_default_bank_account_id" required>
                                        <option value="">Choose account…</option>
                                        @foreach ($clio_bank_accounts as $account)
                                            <option value="{{ $account['account_id'] }}" @selected((string) $client->clio_default_bank_account_id === (string) $account['account_id'])>
                                                {{ $account['account_name'] }}{{ $account['account_type'] ? ' (' . $account['account_type'] . ')' : '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <button type="submit" class="button primary" style="font-size:13px;">Save
                                    account</button>
                            </form>
                        @else
                            <div class="cc-notice error">No Clio bank accounts returned. Reconnect or verify the user
                                has access.</div>
                        @endif
                    </div>
                @endif

                {{-- Default Account (Lawcus) --}}
                @if ($provider === 'lawcus' && $client && $connection)
                    <div class="cc-card">
                        <div class="cc-card-title">Default Lawcus Bank Account</div>
                        <div class="cc-card-desc">Bank account used when recording payments back to Lawcus.</div>
                        @if ($client->lawcus_default_bank_account_name)
                            <div style="font-size:13px;font-weight:600;color:#1a1a2e;margin-bottom:12px;">Currently:
                                {{ $client->lawcus_default_bank_account_name }}</div>
                        @endif
                        @if (!empty($lawcus_bank_account_load_error))
                            <div class="cc-notice error">{{ $lawcus_bank_account_load_error }}</div>
                        @elseif(!empty($lawcus_bank_accounts))
                            <form method="POST" action="{{ route('inbound.lawcus.default-bank-account') }}">
                                @csrf
                                <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                                <div class="cc-field">
                                    <label>Select bank account</label>
                                    <select name="lawcus_default_bank_account_id" required>
                                        <option value="">Choose account…</option>
                                        @foreach ($lawcus_bank_accounts as $account)
                                            <option value="{{ $account['account_id'] }}" @selected((string) $client->lawcus_default_bank_account_id === (string) $account['account_id'])>
                                                {{ $account['account_name'] }}{{ $account['account_type'] ? ' (' . $account['account_type'] . ')' : '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <button type="submit" class="button primary" style="font-size:13px;">Save
                                    account</button>
                            </form>
                        @else
                            <div class="cc-notice error">No Lawcus bank accounts returned. Reconnect or verify the user
                                has access.</div>
                        @endif
                    </div>
                @endif

                {{-- Default Account (Wave) --}}
                @if ($provider === 'wave' && $client && $connection)
                    <div class="cc-card">
                        <div class="cc-card-title">Default Wave Payment Account</div>
                        <div class="cc-card-desc">Wave account where payments are deposited after invoice settlement.
                        </div>
                        @if ($client->wave_default_account_name)
                            <div style="font-size:13px;font-weight:600;color:#1a1a2e;margin-bottom:12px;">Currently:
                                {{ $client->wave_default_account_name }}</div>
                        @endif
                        @if (!empty($wave_account_load_error))
                            <div class="cc-notice error">{{ $wave_account_load_error }}</div>
                        @elseif(!empty($wave_payment_accounts))
                            <form method="POST" action="{{ route('inbound.wave.default-account') }}">
                                @csrf
                                <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                                <div class="cc-field">
                                    <label>Select payment account</label>
                                    <select name="wave_default_account_id" required>
                                        <option value="">Choose account…</option>
                                        @foreach ($wave_payment_accounts as $account)
                                            <option value="{{ $account['account_id'] }}" @selected((string) $client->wave_default_account_id === (string) $account['account_id'])>
                                                {{ $account['account_name'] }}{{ $account['account_type'] ? ' (' . $account['account_type'] . ')' : '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <button type="submit" class="button primary" style="font-size:13px;">Save
                                    account</button>
                            </form>
                        @else
                            <div class="cc-notice error">No Wave accounts returned. Reconnect or verify the user has
                                access.</div>
                        @endif
                    </div>
                @endif

                {{-- Default Account (Zoho) --}}
                @if ($provider === 'zoho' && $client && $connection)
                    <div class="cc-card">
                        <div class="cc-card-title">Default Zoho Deposit Account</div>
                        <div class="cc-card-desc">Zoho Books account where payments are recorded after settlement.
                        </div>
                        @if ($client->zoho_default_account_name)
                            <div style="font-size:13px;font-weight:600;color:#1a1a2e;margin-bottom:12px;">Currently:
                                {{ $client->zoho_default_account_name }}</div>
                        @endif
                        @if (!empty($zoho_account_load_error))
                            <div class="cc-notice error">{{ $zoho_account_load_error }}</div>
                        @elseif(!empty($zoho_payment_accounts))
                            <form method="POST" action="{{ route('inbound.zoho.default-account') }}">
                                @csrf
                                <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                                <div class="cc-field">
                                    <label>Select deposit account</label>
                                    <select name="zoho_default_account_id" required>
                                        <option value="">Choose account…</option>
                                        @foreach ($zoho_payment_accounts as $account)
                                            <option value="{{ $account['account_id'] }}" @selected((string) $client->zoho_default_account_id === (string) $account['account_id'])>
                                                {{ $account['account_name'] }}
                                                ({{ $account['account_type'] ?: 'Account' }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <button type="submit" class="button primary" style="font-size:13px;">Save
                                    account</button>
                            </form>
                        @else
                            <div class="cc-notice error">No Zoho accounts returned. Reconnect or verify the user has
                                access.</div>
                        @endif
                    </div>
                @endif


                {{-- QB Surcharge Split (toggle + account selector) --}}
                @if ($provider === 'quickbooks' && $connection && $client)
                    <div class="cc-card">
                        {{-- Toggle row --}}
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                            <div>
                                <div class="cc-card-title" style="margin-bottom:2px;">Surcharge Income Account</div>
                                <div class="cc-card-desc" style="margin:0;">
                                    When enabled, surcharge is split in QuickBooks: invoice settled at face value,
                                    surcharge posted to a separate income account.
                                </div>
                            </div>
                            <form method="POST" action="{{ route('inbound.quickbooks.surcharge-toggle') }}"
                                id="qb-surcharge-toggle-form">
                                @csrf
                                <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                                <input type="hidden" name="qb_surcharge_enabled" id="qb-surcharge-enabled-val"
                                    value="{{ $client->qb_surcharge_enabled ? '1' : '0' }}">
                                <button type="button" onclick="qbSurchargeToggle()" id="qb-surcharge-btn"
                                    class="button {{ $client->qb_surcharge_enabled ? 'primary' : 'secondary' }}"
                                    style="white-space:nowrap;font-size:13px;min-width:72px;">
                                    {{ $client->qb_surcharge_enabled ? 'ON' : 'OFF' }}
                                </button>
                            </form>
                        </div>

                        {{-- Account selector — only when toggle is ON --}}
                        @if ($client->qb_surcharge_enabled)
                            <div style="margin-top:16px;border-top:1px solid var(--cc-border);padding-top:16px;">
                                @if ($client->qb_surcharge_account_name)
                                    <div style="font-size:13px;font-weight:600;color:#1a1a2e;margin-bottom:12px;">
                                        Currently: {{ $client->qb_surcharge_account_name }}
                                    </div>
                                @endif
                                @error('qb_surcharge_account_id')
                                    <p style="font-size:12px;color:#dc2626;margin-bottom:8px;">{{ $message }}</p>
                                @enderror
                                @if (!empty($qb_account_load_error))
                                    <div class="cc-notice error">{{ $qb_account_load_error }}</div>
                                @elseif(!empty($qb_income_accounts))
                                    <form method="POST"
                                        action="{{ route('inbound.quickbooks.surcharge-account') }}">
                                        @csrf
                                        <input type="hidden" name="pms_client_id"
                                            value="{{ $client->pms_client_id }}">
                                        <div class="cc-field">
                                            <label>Select income account</label>
                                            <select name="qb_surcharge_account_id" required>
                                                <option value="">Choose from income accounts…</option>
                                                @foreach ($qb_income_accounts as $account)
                                                    <option value="{{ $account['account_id'] }}"
                                                        @selected((string) $client->qb_surcharge_account_id === (string) $account['account_id'])>
                                                        {{ $account['account_name'] }}{{ $account['account_type'] ? ' (' . $account['account_type'] . ')' : '' }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <button type="submit" class="button primary" style="font-size:13px;">Save
                                            account</button>
                                    </form>
                                @else
                                    <div class="cc-notice error">No income accounts returned from QuickBooks. Reconnect
                                        or verify the connected user has access.</div>
                                @endif
                            </div>
                        @endif
                    </div>

                    {{-- Payment-link PDF mode --}}
                    @php
                        $qbEmailConfig = $client->emailConfiguration;
                        $qbPdfMode = ($qbEmailConfig && ! $qbEmailConfig->attach_pdf)
                            ? 'disabled'
                            : ($client->qb_use_native_pdf ? 'native' : 'simphub');
                    @endphp
                    <div class="cc-card">
                        <div class="cc-card-title" style="margin-bottom:2px;">Payment-Link PDF</div>
                        <div class="cc-card-desc" style="margin:0 0 12px;">
                            Whether a PDF is attached to the payment-link email at all, and if so, whether
                            it's QuickBooks' own invoice export or the PDF SimpHub generates from the invoice
                            data.
                        </div>
                        <form method="POST" action="{{ route('inbound.quickbooks.pdf-mode') }}"
                            id="qb-pdf-mode-form">
                            @csrf
                            <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                            <input type="hidden" name="qb_pdf_mode" id="qb-pdf-mode-val" value="{{ $qbPdfMode }}">
                            <div
                                style="display:inline-flex;border:1px solid rgba(19,34,56,.15);border-radius:8px;overflow:hidden;font-size:12px;font-weight:700;">
                                <button type="button" id="qb-pdf-mode-disabled" onclick="qbPdfModeSet('disabled')"
                                    style="padding:6px 14px;border:none;cursor:pointer;font-family:inherit;{{ $qbPdfMode === 'disabled' ? 'background:#132238;color:#fff;' : 'background:#fff;color:#9ca3af;' }}">Disabled</button>
                                <button type="button" id="qb-pdf-mode-native" onclick="qbPdfModeSet('native')"
                                    style="padding:6px 14px;border:none;border-left:1px solid rgba(19,34,56,.15);cursor:pointer;font-family:inherit;{{ $qbPdfMode === 'native' ? 'background:#132238;color:#fff;' : 'background:#fff;color:#9ca3af;' }}">QB PDF</button>
                                <button type="button" id="qb-pdf-mode-simphub" onclick="qbPdfModeSet('simphub')"
                                    style="padding:6px 14px;border:none;border-left:1px solid rgba(19,34,56,.15);cursor:pointer;font-family:inherit;{{ $qbPdfMode === 'simphub' ? 'background:#132238;color:#fff;' : 'background:#fff;color:#9ca3af;' }}">SimpHub PDF</button>
                            </div>
                        </form>
                        <script>
                            function qbPdfModeSet(mode) {
                                document.getElementById('qb-pdf-mode-val').value = mode;
                                ['disabled', 'native', 'simphub'].forEach(function(m) {
                                    var btn = document.getElementById('qb-pdf-mode-' + m);
                                    var on = m === mode;
                                    btn.style.background = on ? '#132238' : '#fff';
                                    btn.style.color = on ? '#fff' : '#9ca3af';
                                });
                                document.getElementById('qb-pdf-mode-form').submit();
                            }
                        </script>
                    </div>
                @endif

                {{-- Wave Surcharge Split (toggle + account selector) --}}
                @if ($provider === 'wave' && $connection && $client)
                    <div class="cc-card">
                        {{-- Toggle row --}}
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                            <div>
                                <div class="cc-card-title" style="margin-bottom:2px;">Surcharge Income Account</div>
                                <div class="cc-card-desc" style="margin:0;">
                                    When enabled, surcharge is split in Wave: invoice settled at face value,
                                    surcharge posted to a separate income account.
                                </div>
                            </div>
                            <form method="POST" action="{{ route('inbound.wave.surcharge-toggle') }}"
                                id="wave-surcharge-toggle-form">
                                @csrf
                                <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                                <input type="hidden" name="wave_surcharge_enabled" id="wave-surcharge-enabled-val"
                                    value="{{ $client->wave_surcharge_enabled ? '1' : '0' }}">
                                <button type="button" onclick="waveSurchargeToggle()" id="wave-surcharge-btn"
                                    class="button {{ $client->wave_surcharge_enabled ? 'primary' : 'secondary' }}"
                                    style="white-space:nowrap;font-size:13px;min-width:72px;">
                                    {{ $client->wave_surcharge_enabled ? 'ON' : 'OFF' }}
                                </button>
                            </form>
                        </div>

                        {{-- Account selector — only when toggle is ON --}}
                        @if ($client->wave_surcharge_enabled)
                            <div style="margin-top:16px;border-top:1px solid var(--cc-border);padding-top:16px;">
                                @if ($client->wave_surcharge_account_name)
                                    <div style="font-size:13px;font-weight:600;color:#1a1a2e;margin-bottom:12px;">
                                        Currently: {{ $client->wave_surcharge_account_name }}
                                    </div>
                                @endif
                                @error('wave_surcharge_account_id')
                                    <p style="font-size:12px;color:#dc2626;margin-bottom:8px;">{{ $message }}</p>
                                @enderror
                                @if (!empty($wave_account_load_error))
                                    <div class="cc-notice error">{{ $wave_account_load_error }}</div>
                                @elseif(!empty($wave_income_accounts))
                                    <form method="POST" action="{{ route('inbound.wave.surcharge-account') }}">
                                        @csrf
                                        <input type="hidden" name="pms_client_id"
                                            value="{{ $client->pms_client_id }}">
                                        <div class="cc-field">
                                            <label>Select income account</label>
                                            <select name="wave_surcharge_account_id" required>
                                                <option value="">Choose from income accounts…</option>
                                                @foreach ($wave_income_accounts as $account)
                                                    <option value="{{ $account['account_id'] }}"
                                                        @selected((string) $client->wave_surcharge_account_id === (string) $account['account_id'])>
                                                        {{ $account['account_name'] }}{{ $account['account_type'] ? ' (' . $account['account_type'] . ')' : '' }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <button type="submit" class="button primary" style="font-size:13px;">Save
                                            account</button>
                                    </form>
                                @else
                                    <div class="cc-notice error">No income accounts returned from Wave. Reconnect
                                        or verify the connected user has access.</div>
                                @endif
                            </div>
                        @endif
                    </div>

                    <script>
                        function waveSurchargeToggle() {
                            var inp = document.getElementById('wave-surcharge-enabled-val');
                            var btn = document.getElementById('wave-surcharge-btn');
                            var current = inp.value === '1';
                            var next = !current;
                            inp.value = next ? '1' : '0';
                            btn.textContent = next ? 'ON' : 'OFF';
                            btn.className = next ? 'button primary' : 'button secondary';
                            document.getElementById('wave-surcharge-toggle-form').submit();
                        }
                    </script>
                @endif

                {{-- Auto-Resend on Invoice Change --}}
                @if (in_array($provider, ['quickbooks', 'clio', 'zoho', 'wave'], true) && $connection && $client)
                    <div class="cc-card">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                            <div>
                                <div class="cc-card-title" style="margin-bottom:2px;">Auto-Resend Payment Link on
                                    Invoice Change</div>
                                <div class="cc-card-desc" style="margin:0;">
                                    When enabled, the payment link email is automatically re-sent whenever
                                    {{ $providerLabel ?? ucfirst($provider) }} reports the invoice was updated.
                                </div>
                            </div>
                            <form method="POST" action="{{ route('inbound.' . $provider . '.auto-resend-toggle') }}"
                                id="qb-auto-resend-toggle-form">
                                @csrf
                                <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                                <input type="hidden" name="auto_resend_on_change" id="qb-auto-resend-val"
                                    value="{{ $client->auto_resend_on_change ? '1' : '0' }}">
                                <button type="button" onclick="qbAutoResendToggle()" id="qb-auto-resend-btn"
                                    class="button {{ $client->auto_resend_on_change ? 'primary' : 'secondary' }}"
                                    style="white-space:nowrap;font-size:13px;min-width:72px;">
                                    {{ $client->auto_resend_on_change ? 'ON' : 'OFF' }}
                                </button>
                            </form>
                        </div>
                    </div>

                    <script>
                        function qbAutoResendToggle() {
                            var inp = document.getElementById('qb-auto-resend-val');
                            var btn = document.getElementById('qb-auto-resend-btn');
                            var next = inp.value !== '1';
                            inp.value = next ? '1' : '0';
                            btn.textContent = next ? 'ON' : 'OFF';
                            btn.className = next ? 'button primary' : 'button secondary';
                            document.getElementById('qb-auto-resend-toggle-form').submit();
                        }
                    </script>
                @endif


                {{-- Zoho webhook auto-setup result --}}
                @if ($provider === 'zoho' && $connection && ($webhook_auto_setup_status ?? null))
                    <div class="cc-card"
                        style="{{ $webhook_auto_setup_status === 'success' ? 'background:#f0fdf4;border-color:#bbf7d0;' : 'background:#fef2f2;border-color:#fecaca;' }}">
                        @if ($webhook_auto_setup_status === 'success')
                            <div class="cc-card-title" style="color:#15803d;">&#10003; Webhook configured
                                automatically</div>
                            <div style="font-size:13px;color:#166534;margin-top:4px;">
                                Zoho Books will notify this middleware when invoices are created.
                                @if (!empty($webhook_id))
                                    · Webhook <code>{{ $webhook_id }}</code>
                                @endif
                                @if (!empty($workflow_id))
                                    · Workflow <code>{{ $workflow_id }}</code>
                                @endif
                            </div>
                        @else
                            <div class="cc-card-title" style="color:#dc2626;">Automatic webhook setup failed</div>
                            @if (!empty($webhook_auto_setup_error))
                                <div style="font-size:13px;color:#991b1b;margin-top:4px;">
                                    {{ $webhook_auto_setup_error }}</div>
                            @endif
                            <div style="font-size:13px;color:#7f1d1d;margin-top:4px;">Reconnect Zoho to retry, or
                                configure the webhook manually in the Webhooks tab.</div>
                        @endif
                    </div>
                @endif

                {{-- Wave integration info --}}
                @if ($provider === 'wave')
                    {{-- <div class="cc-card">
                        <div class="cc-card-title">How Wave Integration Works</div>
                        <div class="cc-card-desc">End-to-end flow from invoice approval to payment recording.</div>
                        <div
                            style="margin-bottom:16px;border-radius:8px;overflow:hidden;border:1px solid var(--cc-border);">
                            <div style="position:relative;padding-bottom:56.25%;height:0;">
                                <iframe src="https://www.loom.com/embed/22e129965ac649dcbe440078e3b4cbed"
                                    frameborder="0" allowfullscreen
                                    style="position:absolute;top:0;left:0;width:100%;height:100%;"></iframe>
                            </div>
                        </div>
                        <div
                            style="background:var(--cc-info-bg,#eff6ff);border:1px solid var(--cc-info-border,#bfdbfe);border-radius:8px;padding:12px 14px;font-size:13px;color:#1e40af;line-height:1.6;margin-bottom:12px;">
                            <strong style="display:block;margin-bottom:4px;">Payment flow</strong>
                            <ol style="margin:0 0 0 16px;padding:0;">
                                <li>Approve invoice in Wave → Wave fires <code
                                        style="background:#dbeafe;padding:1px 4px;border-radius:3px;">invoice.approved</code>
                                    to this middleware.</li>
                                <li>Middleware creates a payment session and emails a payment link.</li>
                                <li>Customer pays → middleware records it in Wave as <strong>Paid</strong>.</li>
                            </ol>
                        </div>
                        <div
                            style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:12px 14px;font-size:13px;color:#9a3412;line-height:1.6;">
                            <strong style="display:block;margin-bottom:4px;">Action needed for auto-resend / auto-disable on invoice changes</strong>
                            Wave's webhook topics are configured once for this app in Wave's own Developer Dashboard,
                            not per client here. To have the payment link automatically re-sent when an invoice is
                            edited, or disabled when it's deleted, the update and delete invoice topics must also be
                            enabled there (only <code style="background:#ffedd5;padding:1px 4px;border-radius:3px;">invoice.approved</code>
                            is enabled today).
                        </div>
                    </div> --}}
                @endif


                {{-- Company Logo --}}
                <x-inbound::client-settings-card :client="$client" :available-gateways="[]" :show-left-col="false" :show-gateways="false"
                    :show-fees="false" :show-logo="true" :compact="true" />

                <x-inbound::notification-settings-form :client="$client" :form-action="route('inbound.clients.update-notification-settings', $client->pms_client_id)" />

                {{-- Payment Reminders — applies uniformly across all PMS providers --}}
                <div class="cc-card">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
                        <div class="cc-card-title">Payment Reminders</div>
                    </div>
                    <div class="cc-card-desc">Automatically re-send the payment link on a schedule while the
                        invoice is still unpaid.</div>

                    <form method="POST"
                        action="{{ route('inbound.clients.update-reminder-settings', $client->pms_client_id) }}"
                        id="reminders-form">
                        @csrf
                        <div
                            style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#f8f9fb;border:1px solid rgba(19,34,56,.08);border-radius:10px;margin:14px 0;">
                            <div style="font-size:14px;font-weight:600;color:var(--cc-text);">Enable Payment Reminders
                            </div>
                            <div
                                style="display:flex;border:1px solid rgba(19,34,56,.15);border-radius:8px;overflow:hidden;font-size:12px;font-weight:700;">
                                <input type="hidden" name="reminders_enabled" id="reminders-enabled-val"
                                    value="{{ $client->reminders_enabled ? '1' : '0' }}">
                                <button type="button" id="reminders-on" onclick="remindersToggle(true)"
                                    style="padding:5px 14px;border:none;cursor:pointer;font-family:inherit;{{ $client->reminders_enabled ? 'background:#132238;color:#fff;' : 'background:#fff;color:#9ca3af;' }}">ON</button>
                                <button type="button" id="reminders-off" onclick="remindersToggle(false)"
                                    style="padding:5px 14px;border:none;border-left:1px solid rgba(19,34,56,.15);cursor:pointer;font-family:inherit;{{ $client->reminders_enabled ? 'background:#fff;color:#9ca3af;' : 'background:#f1f5f9;color:#374151;' }}">OFF</button>
                            </div>
                        </div>

                        <div id="reminders-section" style="{{ $client->reminders_enabled ? '' : 'display:none;' }}">
                            <div class="cc-field" style="margin-bottom:14px;">
                                <label>Reminder Schedule (days after the first payment email, comma-separated)</label>
                                <input type="text" name="reminder_schedule_days_raw" id="reminder-schedule-input"
                                    value="{{ old('reminder_schedule_days_raw', implode(',', $client->reminderScheduleDays())) }}"
                                    placeholder="3,7,14,30,45">
                                @error('reminder_schedule_days')
                                    <p style="font-size:12px;color:#dc2626;margin-top:3px;">{{ $message }}</p>
                                @enderror
                                <div style="font-size:12px;color:var(--cc-text-3);margin-top:3px;">Must be ascending,
                                    unique, 1-365 each, up to 10 steps. Leave default to use the system schedule.</div>
                            </div>

                            <div class="cc-field" style="margin-bottom:14px;">
                                <label>Reminder Email Subject</label>
                                <input type="text" name="reminder_subject_template"
                                    value="{{ old('reminder_subject_template', $client->reminder_subject_template ?? 'Reminder: Invoice {invoice_number} is awaiting payment') }}">
                            </div>

                            <div style="font-size:12px;color:var(--cc-text-2);margin-bottom:14px;">Reminders are sent
                                only while the invoice is unpaid. They stop automatically when the invoice is paid,
                                voided, or deleted.</div>
                        </div>

                        <button type="submit" class="button primary" style="font-size:13px;">Save reminder
                            settings</button>
                    </form>
                </div>

                <script>
                    function remindersToggle(on) {
                        document.getElementById('reminders-enabled-val').value = on ? '1' : '0';
                        document.getElementById('reminders-on').style.background = on ? '#132238' : '#fff';
                        document.getElementById('reminders-on').style.color = on ? '#fff' : '#9ca3af';
                        document.getElementById('reminders-off').style.background = on ? '#fff' : '#f1f5f9';
                        document.getElementById('reminders-off').style.color = on ? '#9ca3af' : '#374151';
                        document.getElementById('reminders-section').style.display = on ? '' : 'none';
                        var input = document.getElementById('reminder-schedule-input');
                        if (on && input.value.trim() === '') input.value = '3,7,14,30,45';
                    }

                    (function() {
                        var form = document.getElementById('reminders-form');
                        if (!form) return;
                        form.addEventListener('submit', function(e) {
                            var enabled = document.getElementById('reminders-enabled-val').value === '1';
                            var hidden = form.querySelectorAll('input[name="reminder_schedule_days[]"]');
                            hidden.forEach(function(el) {
                                el.remove();
                            });
                            if (!enabled) return;

                            var raw = document.getElementById('reminder-schedule-input').value.trim();
                            var days = raw.split(',').map(function(s) {
                                return parseInt(s.trim(), 10);
                            }).filter(function(n) {
                                return !isNaN(n);
                            });
                            var sorted = days.slice().sort(function(a, b) {
                                return a - b;
                            });
                            var unique = Array.from(new Set(days));

                            if (days.length === 0 || days.length > 10 || JSON.stringify(days) !== JSON.stringify(sorted) ||
                                unique.length !== days.length) {
                                e.preventDefault();
                                alert('Cadence days must be unique, ascending, and at most 10 steps.');
                                return;
                            }

                            days.forEach(function(day) {
                                var input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = 'reminder_schedule_days[]';
                                input.value = day;
                                form.appendChild(input);
                            });
                        });
                    })();
                </script>

            </div>{{-- end cc-panel-connection --}}

            {{-- ── Gateways tab ── --}}
            <div id="cc-panel-gateways" class="cc-tab-panel">
                <x-inbound::client-settings-card :client="$client" :available-gateways="$availableGateways" :show-left-col="false"
                    :show-fees="false" :show-logo="false" :compact="true" />
            </div>

            {{-- ── Fee Configuration tab ── --}}
            <div id="cc-panel-fees" class="cc-tab-panel">
                <div class="cc-card">
                    <div class="cc-card-title">Processing Fee Configuration</div>
                    <div class="cc-card-desc">Configure processing fees passed to customers at checkout.</div>
                    <x-inbound::fee-config-form :client="$client" :form-action="route('inbound.clients.update-fees', $client->pms_client_id)" btn-class="button primary"
                        :features="$features" />
                </div>

                {{-- ── QB-specific configuration (only for QuickBooks) ── --}}
                @if ($provider === 'quickbooks' && $client)
                    @php
                        $midRoutes = \Modules\Inbound\Models\ClientMidRoute::where('client_id', $client->id)
                            ->get()
                            ->keyBy(fn($r) => $r->route_type . '_' . $r->gateway);
                        $pausedGateways = array_map('strtolower', $client->paused_payment_gateways ?? []);
                        $clientGateways = array_values(
                            array_diff(
                                array_map('strtolower', $client->allowed_payment_gateways ?? []),
                                $pausedGateways,
                            ),
                        );
                        $gwCredsAll = (array) ($client->gateway_credentials ?? []);
                    @endphp

                    {{-- Per-Invoice Fee Override --}}
                    <div class="cc-card" id="qb-override-card">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
                            <div class="cc-card-title">Per-Invoice Fee Override</div>
                            <button type="button" onclick="toggleCollapse('qb-override-body','qb-override-chev')"
                                style="background:none;border:none;cursor:pointer;font-size:12px;color:var(--cc-text-2);display:flex;align-items:center;gap:4px;">
                                <span id="qb-override-chev">&#9660;</span>
                            </button>
                        </div>
                        <div class="cc-card-desc">Allow individual QuickBooks invoices to override the client-level fee
                            setting using a custom Yes/No field.</div>

                        <div id="qb-override-body">
                            <form method="POST"
                                action="{{ route('inbound.clients.update-qb-settings', $client->pms_client_id) }}">
                                @csrf
                                <input type="hidden" name="qb_multi_mid_enabled"
                                    value="{{ $client->qb_multi_mid_enabled ? '1' : '0' }}">

                                {{-- Enable toggle --}}
                                <div
                                    style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#f8f9fb;border:1px solid rgba(19,34,56,.08);border-radius:10px;margin-bottom:14px;">
                                    <div>
                                        <div style="font-size:14px;font-weight:600;color:var(--cc-text);">Enable
                                            Per-Invoice Override</div>
                                        <div style="font-size:12px;color:var(--cc-text-2);margin-top:1px;">Read a QB
                                            custom field on each invoice to override the fee setting.</div>
                                    </div>
                                    <div
                                        style="display:flex;border:1px solid rgba(19,34,56,.15);border-radius:8px;overflow:hidden;font-size:12px;font-weight:700;">
                                        <input type="hidden" name="qb_fee_override_enabled" id="qb-override-val"
                                            value="{{ $client->qb_fee_override_enabled ? '1' : '0' }}">
                                        <button type="button" id="qb-ovr-on"
                                            onclick="qbToggle('qb-override-val','qb-ovr-on','qb-ovr-off',true,'qb-ovr-section')"
                                            style="padding:5px 14px;border:none;cursor:pointer;font-family:inherit;{{ $client->qb_fee_override_enabled ? 'background:#132238;color:#fff;' : 'background:#fff;color:#9ca3af;' }}">ON</button>
                                        <button type="button" id="qb-ovr-off"
                                            onclick="qbToggle('qb-override-val','qb-ovr-on','qb-ovr-off',false,'qb-ovr-section')"
                                            style="padding:5px 14px;border:none;border-left:1px solid rgba(19,34,56,.15);cursor:pointer;font-family:inherit;{{ $client->qb_fee_override_enabled ? 'background:#fff;color:#9ca3af;' : 'background:#f1f5f9;color:#374151;' }}">OFF</button>
                                    </div>
                                </div>

                                {{-- Field name + mapping (shown when override is ON) --}}
                                <div id="qb-ovr-section"
                                    style="{{ $client->qb_fee_override_enabled ? '' : 'display:none;' }}">
                                    <div class="cc-field" style="margin-bottom:14px;">
                                        <label>QBO Custom Field Name</label>
                                        <input type="text" name="qb_fee_override_field"
                                            value="{{ old('qb_fee_override_field', $client->qb_fee_override_field ?? 'Cash Discount') }}"
                                            placeholder="e.g. Fee Override">
                                        <div style="font-size:12px;color:var(--cc-text-3);margin-top:3px;">Must match
                                            the exact custom field name on the QuickBooks invoice template.</div>
                                    </div>

                                    {{-- How to set up the custom field in QuickBooks --}}
                                    <div
                                        style="padding:12px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:var(--cc-r-md);margin-bottom:14px;">
                                        <div style="font-size:13px;color:#1e40af;line-height:1.6;">
                                            <strong>This only works if the custom field is set up exactly this way in
                                                QuickBooks</strong>
                                            (Settings &rarr; Custom fields &rarr; Add field) &mdash; otherwise the
                                            override is silently ignored.
                                            <a href="javascript:void(0)"
                                                onclick="document.getElementById('qbCustomFieldSetupModal').style.display='flex'"
                                                style="color:#1e40af;font-weight:700;text-decoration:underline;white-space:nowrap;">View
                                                setup screenshot &rarr;</a>
                                        </div>
                                    </div>

                                    {{-- QB Custom Field Setup Screenshot Modal --}}
                                    <div id="qbCustomFieldSetupModal" class="cc-preview-overlay"
                                        onclick="if(event.target === this) this.style.display='none';">
                                        <div class="cc-preview-modal">
                                            <div class="cc-preview-header">
                                                <div class="cc-preview-title">QuickBooks Custom Field Setup</div>
                                                <button type="button" class="cc-preview-close"
                                                    onclick="document.getElementById('qbCustomFieldSetupModal').style.display='none'">✕</button>
                                            </div>
                                            <div class="cc-preview-body">
                                                <img src="{{ asset('images/qb-custom-field-setup.png') }}"
                                                    alt="QuickBooks Add custom field dialog: Name matching the field above, Data type = Text and number, Category = Transaction, Invoice checked under Select forms"
                                                    style="max-width:100%;height:auto;border-radius:6px;display:block;">
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Field mapping table --}}
                                    <div style="margin-bottom:14px;">
                                        <div
                                            style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--cc-text-2);margin-bottom:8px;">
                                            Field Value Mapping</div>
                                        <table
                                            style="width:100%;border-collapse:collapse;font-size:13px;border:1px solid var(--cc-border);border-radius:var(--cc-r-md);overflow:hidden;">
                                            <thead style="background:#f9fafb;">
                                                <tr>
                                                    <th
                                                        style="text-align:left;padding:8px 12px;font-size:11px;font-weight:700;color:var(--cc-text-2);text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid var(--cc-border);">
                                                        Field Value</th>
                                                    <th
                                                        style="text-align:left;padding:8px 12px;font-size:11px;font-weight:700;color:var(--cc-text-2);text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid var(--cc-border);">
                                                        Behaviour</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr style="border-top:1px solid var(--cc-border-light);">
                                                    <td style="padding:9px 12px;"><code
                                                            style="background:#dcfce7;color:#166534;padding:2px 7px;border-radius:4px;font-size:12px;">Yes</code>
                                                    </td>
                                                    <td
                                                        style="padding:9px 12px;color:var(--cc-text-2);font-size:13px;">
                                                        Pass fees to customer — fee shown on payment page</td>
                                                </tr>
                                                <tr style="border-top:1px solid var(--cc-border-light);">
                                                    <td style="padding:9px 12px;"><code
                                                            style="background:#fce4ec;color:#b71c1c;padding:2px 7px;border-radius:4px;font-size:12px;">No</code>
                                                    </td>
                                                    <td
                                                        style="padding:9px 12px;color:var(--cc-text-2);font-size:13px;">
                                                        Merchant absorbs fees — flat amount shown</td>
                                                </tr>
                                                <tr style="border-top:1px solid var(--cc-border-light);">
                                                    <td style="padding:9px 12px;"><code
                                                            style="background:#f3f4f6;color:var(--cc-text-2);padding:2px 7px;border-radius:4px;font-size:12px;">Not
                                                            set / anything else</code></td>
                                                    <td
                                                        style="padding:9px 12px;color:var(--cc-text-2);font-size:13px;">
                                                        Merchant absorbs fees — flat amount shown (treated the same as
                                                        "No")</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                </div>{{-- end qb-ovr-section --}}

                                <button type="submit" class="button primary"
                                    style="font-size:13px;margin-top:14px;">Save override settings</button>
                            </form>
                        </div>
                    </div>

                    {{-- Multi-MID Routing --}}
                    <div class="cc-card" id="qb-mid-card">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
                            <div class="cc-card-title">Multi-MID Routing</div>
                            <button type="button" onclick="toggleCollapse('qb-mid-body','qb-mid-chev')"
                                style="background:none;border:none;cursor:pointer;font-size:12px;color:var(--cc-text-2);display:flex;align-items:center;gap:4px;">
                                <span id="qb-mid-chev">&#9660;</span>
                            </button>
                        </div>
                        <div class="cc-card-desc">Route transactions to different merchant accounts based on the
                            per-invoice fee toggle.</div>

                        <div id="qb-mid-body">
                            @if (!$client->qb_fee_override_enabled)
                                <div
                                    style="padding:12px 14px;background:#fffbeb;border:1px solid #fde68a;border-radius:var(--cc-r-md);font-size:13px;color:#92400e;margin-bottom:14px;">
                                    &#9888; Per-Invoice Fee Override must be enabled above before Multi-MID Routing can
                                    take effect.
                                </div>
                            @endif

                            <form method="POST"
                                action="{{ route('inbound.clients.save-mid-routes', $client->pms_client_id) }}"
                                id="mid-routes-form">
                                @csrf

                                {{-- Enable Multi-MID toggle --}}
                                <div
                                    style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#f8f9fb;border:1px solid rgba(19,34,56,.08);border-radius:10px;margin-bottom:16px;">
                                    <div>
                                        <div style="font-size:14px;font-weight:600;color:var(--cc-text);">Enable
                                            Multi-MID Routing</div>
                                        <div style="font-size:12px;color:var(--cc-text-2);margin-top:1px;">Use
                                            different MIDs based on whether fees are passed or absorbed.</div>
                                    </div>
                                    <div
                                        style="display:flex;border:1px solid rgba(19,34,56,.15);border-radius:8px;overflow:hidden;font-size:12px;font-weight:700;">
                                        <input type="hidden" name="qb_multi_mid_enabled" id="qb-mid-val"
                                            value="{{ $client->qb_multi_mid_enabled ? '1' : '0' }}">
                                        <button type="button" id="qb-mid-on"
                                            onclick="qbToggle('qb-mid-val','qb-mid-on','qb-mid-off',true,'qb-mid-section')"
                                            style="padding:5px 14px;border:none;cursor:pointer;font-family:inherit;{{ $client->qb_multi_mid_enabled ? 'background:#132238;color:#fff;' : 'background:#fff;color:#9ca3af;' }}">ON</button>
                                        <button type="button" id="qb-mid-off"
                                            onclick="qbToggle('qb-mid-val','qb-mid-on','qb-mid-off',false,'qb-mid-section')"
                                            style="padding:5px 14px;border:none;border-left:1px solid rgba(19,34,56,.15);cursor:pointer;font-family:inherit;{{ $client->qb_multi_mid_enabled ? 'background:#fff;color:#9ca3af;' : 'background:#f1f5f9;color:#374151;' }}">OFF</button>
                                    </div>
                                </div>

                                <div id="qb-mid-section"
                                    style="{{ $client->qb_multi_mid_enabled ? '' : 'display:none;' }}">

                                    @foreach (['fees_on' => ['label' => 'TOGGLE ON — Fees Passed Through', 'desc' => 'Used when the QB custom field = Yes. Customer pays the processing fee.', 'color' => '#f0fdf4', 'border' => '#a7f3d0', 'pill_bg' => '#065f46', 'pill_color' => '#fff'], 'fees_off' => ['label' => 'TOGGLE OFF — Fees Absorbed', 'desc' => 'Used when the QB custom field = No or not set. Merchant absorbs the fee.', 'color' => '#f9fafb', 'border' => '#e5e7eb', 'pill_bg' => '#6b7280', 'pill_color' => '#fff']] as $routeType => $routeConfig)
                                        <div
                                            style="margin-bottom:16px;border:1px solid {{ $routeConfig['border'] }};border-radius:var(--cc-r-md);overflow:hidden;">
                                            <div
                                                style="background:{{ $routeConfig['color'] }};padding:10px 16px;border-bottom:1px solid {{ $routeConfig['border'] }};display:flex;align-items:center;gap:10px;">
                                                <span
                                                    style="background:{{ $routeConfig['pill_bg'] }};color:{{ $routeConfig['pill_color'] }};padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;">{{ strtoupper(str_replace('_', ' ', $routeType)) }}</span>
                                                <span
                                                    style="font-size:13px;font-weight:600;color:var(--cc-text);">{{ $routeConfig['label'] }}</span>
                                            </div>
                                            <div
                                                style="padding:14px 16px;background:#fff;font-size:12px;color:var(--cc-text-2);border-bottom:1px solid {{ $routeConfig['border'] }};">
                                                {{ $routeConfig['desc'] }}</div>
                                            <div style="padding:14px 16px;">
                                                @if (count($clientGateways))
                                                    @foreach ($clientGateways as $gw)
                                                        @php
                                                            $key = $routeType . '_' . $gw;
                                                            $existing = $midRoutes[$key] ?? null;
                                                            $gwUp = strtoupper($gw);
                                                            $idx = $routeType . '_' . $gw;
                                                        @endphp
                                                        <div
                                                            style="background:#f9fafb;border:1px solid var(--cc-border);border-radius:var(--cc-r-md);padding:14px;margin-bottom:10px;">
                                                            <div
                                                                style="font-size:13px;font-weight:700;color:var(--cc-text);margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;">
                                                                <span>{{ $gwUp }}</span>
                                                                @if ($existing)
                                                                    <label
                                                                        style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#dc2626;cursor:pointer;">
                                                                        <input type="checkbox"
                                                                            name="routes[{{ $idx }}][remove]"
                                                                            value="1"
                                                                            onchange="var f=document.getElementById('mid-fields-{{ $idx }}'); f.style.opacity=this.checked?'0.4':'1'; f.querySelectorAll('input').forEach(function(el){ el.disabled=this.checked; }, this);">
                                                                        Remove this configuration
                                                                    </label>
                                                                @endif
                                                            </div>

                                                            <input type="hidden"
                                                                name="routes[{{ $idx }}][route_type]"
                                                                value="{{ $routeType }}">
                                                            <input type="hidden"
                                                                name="routes[{{ $idx }}][gateway]"
                                                                value="{{ $gw }}">

                                                            <div id="mid-fields-{{ $idx }}">
                                                                <div
                                                                    style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                                                                    <div class="cc-field" style="margin:0;">
                                                                        <label>MID Identifier</label>
                                                                        <input type="text"
                                                                            name="routes[{{ $idx }}][mid_identifier]"
                                                                            value="{{ old("routes.$idx.mid_identifier", $existing?->mid_identifier) }}"
                                                                            placeholder="e.g. MID_001">
                                                                    </div>
                                                                    <div class="cc-field" style="margin:0;">
                                                                        <label>MID Label <span
                                                                                style="font-weight:400;color:var(--cc-text-3);">(optional)</span></label>
                                                                        <input type="text"
                                                                            name="routes[{{ $idx }}][mid_label]"
                                                                            value="{{ old("routes.$idx.mid_label", $existing?->mid_label) }}"
                                                                            placeholder="e.g. {{ $gwUp }} Cash Discount">
                                                                    </div>
                                                                </div>
                                                                @if ($gw === 'fluidpay')
                                                                    <div class="cc-field" style="margin:0 0 10px;">
                                                                        <label>Processor ID</label>
                                                                        <input type="text"
                                                                            class="fluidpay-processor-input"
                                                                            list="fluidpay-processors-{{ $idx }}"
                                                                            name="routes[{{ $idx }}][processor_id]"
                                                                            value="{{ old("routes.$idx.processor_id", $existing?->processor_id) }}"
                                                                            placeholder="e.g. proc_a1b2c3"
                                                                            autocomplete="off">
                                                                        <datalist id="fluidpay-processors-{{ $idx }}" class="fluidpay-processor-datalist"></datalist>
                                                                        <div class="fluidpay-processor-hint"
                                                                            style="font-size:12px;color:var(--cc-text-3);margin-top:3px;">
                                                                            FluidPay Processor ID for this MID —
                                                                            found in FluidPay &rarr; Manage &rarr;
                                                                            Processors ("ID" column). Start typing
                                                                            to pick from the account's processors.
                                                                        </div>
                                                                    </div>
                                                                @endif
                                                                @php
                                                                    $gwEnv = $gwCredsAll['environment'] ?? 'sandbox';
                                                                    $gwEnvLabel =
                                                                        $gwEnv === 'production'
                                                                            ? 'Production'
                                                                            : 'Sandbox';
                                                                @endphp
                                                                <input type="hidden"
                                                                    name="routes[{{ $idx }}][environment]"
                                                                    value="{{ $gwEnv }}">
                                                                <div
                                                                    style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                                                                    <div class="cc-field" style="margin:0;">
                                                                        <label>Rate (%)</label>
                                                                        <input type="number"
                                                                            name="routes[{{ $idx }}][rate_percent]"
                                                                            step="0.01" min="0"
                                                                            max="99.99" placeholder="0.00"
                                                                            value="{{ old("routes.$idx.rate_percent", $existing?->rate_percent) }}">
                                                                    </div>
                                                                    <div class="cc-field" style="margin:0;">
                                                                        <label>Environment</label>
                                                                        <div
                                                                            style="padding:8px 12px;border:1px solid rgba(19,34,56,.1);border-radius:8px;font-size:13px;background:#f3f4f6;color:#374151;display:flex;align-items:center;gap:6px;">
                                                                            <span
                                                                                style="width:8px;height:8px;border-radius:50%;background:{{ $gwEnv === 'production' ? '#16a34a' : '#f59e0b' }};flex-shrink:0;"></span>
                                                                            {{ $gwEnvLabel }}
                                                                            <span
                                                                                style="font-size:11px;color:#9ca3af;margin-left:4px;">(from
                                                                                Gateway Credentials)</span>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                @php
                                                                    $creds = $existing?->credentials ?? [];
                                                                    $credFields = match ($gw) {
                                                                        'fluidpay' => [
                                                                            [
                                                                                'key' => 'api_key',
                                                                                'label' => 'Private Key',
                                                                                'type' => 'password',
                                                                            ],
                                                                            [
                                                                                'key' => 'public_key',
                                                                                'label' => 'Public Key (tokenizer)',
                                                                                'type' => 'text',
                                                                            ],
                                                                        ],
                                                                        'paya' => [
                                                                            [
                                                                                'key' => 'username',
                                                                                'label' => 'Vault Username',
                                                                                'type' => 'text',
                                                                            ],
                                                                            [
                                                                                'key' => 'password',
                                                                                'label' => 'Vault Password',
                                                                                'type' => 'password',
                                                                            ],
                                                                            [
                                                                                'key' => 'terminal_id',
                                                                                'label' => 'Terminal ID',
                                                                                'type' => 'text',
                                                                            ],
                                                                        ],
                                                                        'nmi' => [
                                                                            [
                                                                                'key' => 'security_key',
                                                                                'label' => 'Security Key',
                                                                                'type' => 'password',
                                                                            ],
                                                                            [
                                                                                'key' => 'public_key',
                                                                                'label' => 'Public Key',
                                                                                'type' => 'text',
                                                                            ],
                                                                        ],
                                                                        default => [
                                                                            [
                                                                                'key' => 'api_key',
                                                                                'label' => 'API Key',
                                                                                'type' => 'password',
                                                                            ],
                                                                        ],
                                                                    };
                                                                @endphp
                                                                <div
                                                                    style="border-top:1px solid var(--cc-border-light);padding-top:10px;margin-top:4px;">
                                                                    <div
                                                                        style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--cc-text-2);margin-bottom:8px;">
                                                                        API Credentials</div>
                                                                    <div
                                                                        style="display:grid;grid-template-columns:repeat({{ min(count($credFields), 2) }},1fr);gap:10px;">
                                                                        @foreach ($credFields as $cf)
                                                                            @php
                                                                                $isConfigured = !empty(
                                                                                    $creds[$cf['key']] ?? null
                                                                                );
                                                                                $placeholder = $isConfigured
                                                                                    ? 'Configured — leave blank to keep'
                                                                                    : '';
                                                                            @endphp
                                                                            <div class="cc-field" style="margin:0;">
                                                                                <label>
                                                                                    {{ $cf['label'] }}
                                                                                    @if ($isConfigured)
                                                                                        <span
                                                                                            style="font-size:11px;font-weight:600;color:#16a34a;margin-left:6px;">✓
                                                                                            Configured</span>
                                                                                    @endif
                                                                                </label>
                                                                                <input type="{{ $cf['type'] }}"
                                                                                    name="routes[{{ $idx }}][credentials][{{ $cf['key'] }}]"
                                                                                    value=""
                                                                                    placeholder="{{ $placeholder }}">
                                                                            </div>
                                                                        @endforeach
                                                                    </div>
                                                                </div>
                                                            </div>{{-- end mid-fields-{{ $idx }} --}}
                                                        </div>
                                                    @endforeach
                                                @else
                                                    <div style="font-size:13px;color:var(--cc-text-2);padding:8px 0;">
                                                        No gateways configured. Add gateways in the Gateways tab first.
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach

                                </div>{{-- end qb-mid-section --}}

                                <button type="submit" class="button primary"
                                    style="font-size:13px;margin-top:14px;">Save MID routes</button>
                            </form>

                            {{-- Also update qb_multi_mid_enabled when saving MID routes --}}
                            <script>
                                document.getElementById('mid-routes-form').addEventListener('submit', function() {
                                    // sync the hidden field from the toggle
                                    this.querySelector('input[name=qb_multi_mid_enabled]').value =
                                        document.getElementById('qb-mid-val').value;
                                });
                            </script>

                            {{-- Populate the Processor ID suggestions from FluidPay's own processor list --}}
                            <script>
                                (function() {
                                    var datalists = document.querySelectorAll('.fluidpay-processor-datalist');
                                    if (!datalists.length) return;

                                    fetch('{{ route('inbound.clients.fluidpay-processors', $client->pms_client_id) }}', {
                                            headers: {
                                                'Accept': 'application/json'
                                            }
                                        })
                                        .then(function(r) {
                                            return r.json();
                                        })
                                        .then(function(data) {
                                            if (!data.success) {
                                                console.warn('FluidPay processors: ' + (data.message ||
                                                    'unknown error'));
                                                return;
                                            }
                                            (data.processors || []).forEach(function(p) {
                                                datalists.forEach(function(dl) {
                                                    var opt = document.createElement('option');
                                                    opt.value = p.id;
                                                    opt.textContent = p.name + (p.status ? ' (' + p.status +
                                                        ')' : '');
                                                    dl.appendChild(opt);
                                                });
                                            });
                                        })
                                        .catch(function(err) {
                                            console.warn('FluidPay processors fetch failed', err);
                                        });
                                })();
                            </script>
                        </div>
                    </div>

                    <script>
                        function toggleCollapse(bodyId, chevId) {
                            const body = document.getElementById(bodyId);
                            const chev = document.getElementById(chevId);
                            const open = body.style.display !== 'none';
                            body.style.display = open ? 'none' : '';
                            chev.innerHTML = open ? '&#9654;' : '&#9660;';
                        }

                        function qbToggle(valId, onId, offId, on, sectionId) {
                            document.getElementById(valId).value = on ? '1' : '0';
                            document.getElementById(onId).style.background = on ? '#132238' : '#fff';
                            document.getElementById(onId).style.color = on ? '#fff' : '#9ca3af';
                            document.getElementById(offId).style.background = on ? '#fff' : '#f1f5f9';
                            document.getElementById(offId).style.color = on ? '#9ca3af' : '#374151';
                            if (sectionId) document.getElementById(sectionId).style.display = on ? '' : 'none';
                        }

                        function qbSurchargeToggle() {
                            var inp = document.getElementById('qb-surcharge-enabled-val');
                            var btn = document.getElementById('qb-surcharge-btn');
                            var current = inp.value === '1';
                            var next = !current;
                            inp.value = next ? '1' : '0';
                            btn.textContent = next ? 'ON' : 'OFF';
                            btn.className = next ? 'button primary' : 'button secondary';
                            document.getElementById('qb-surcharge-toggle-form').submit();
                        }

                    </script>
                @endif

            </div>

            {{-- ── Webhooks tab ── --}}
            <div id="cc-panel-webhooks" class="cc-tab-panel">

                @if (!empty($webhook_instructions))
                    {{-- Manual webhook setup (Zoho) --}}
                    <div class="cc-card">
                        <div class="cc-card-title">Webhook Setup</div>
                        <div class="cc-card-desc">Configure {{ $providerLabel }} to send invoice events to this
                            middleware.</div>

                        @if (!empty($webhook_instructions['demo_video_url']))
                            <div
                                style="margin-bottom:16px;padding:12px 14px;background:var(--cc-info-bg,#eff6ff);border:1px solid var(--cc-info-border,#bfdbfe);border-radius:var(--cc-r-md);">
                                <div
                                    style="font-weight:600;color:var(--cc-info-text,#1e40af);margin-bottom:6px;font-size:13px;">
                                    Need a walkthrough?</div>
                                <a href="{{ $webhook_instructions['demo_video_url'] }}" target="_blank"
                                    rel="noopener noreferrer" class="button primary" style="font-size:13px;">View
                                    setup demo</a>
                            </div>
                        @endif

                        <ol
                            style="margin:0 0 16px 20px;padding:0;color:var(--cc-text-2);line-height:1.7;font-size:14px;">
                            @foreach ($webhook_instructions['steps'] as $index => $instruction)
                                <li style="margin-bottom:8px;">{!! $instruction !!}</li>
                                @if ($index === 2)
                                    <div
                                        style="background:#f9fafb;border:1px solid var(--cc-border);border-radius:var(--cc-r-md);padding:14px;margin:12px 0;font-size:13px;">
                                        <div style="margin-bottom:6px;"><strong>Name:</strong> Invoice Create
                                            &nbsp;·&nbsp; <strong>Module:</strong> Invoices</div>
                                        <div class="cc-field" style="margin-bottom:10px;">
                                            <label>URL to Notify</label>
                                            <div class="cc-copy-row">
                                                <input type="text" id="wh-url-input"
                                                    value="{{ $webhook_instructions['url'] }}" readonly
                                                    style="flex:1;padding:8px 10px;border:1px solid var(--cc-border);border-radius:var(--cc-r-sm);font:inherit;font-size:13px;background:#f3f4f6;color:var(--cc-text);">
                                                <button class="cc-copy-btn"
                                                    onclick="whCopy('wh-url-input',this)">Copy</button>
                                            </div>
                                        </div>
                                        <div class="cc-field">
                                            <label>Body (Raw JSON)</label>
                                            <div style="position:relative;">
                                                <textarea id="wh-json-input" rows="6" readonly
                                                    style="width:100%;padding:10px;border:1px solid var(--cc-border);border-radius:var(--cc-r-sm);background:#1e293b;color:#e2e8f0;font-family:ui-monospace,monospace;font-size:12px;box-sizing:border-box;resize:none;">{{ @$webhook_instructions['json'] }}</textarea>
                                                <button class="cc-copy-btn" onclick="whCopy('wh-json-input',this)"
                                                    style="position:absolute;top:8px;right:8px;background:rgba(255,255,255,.15);font-size:11px;">Copy
                                                    JSON</button>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </ol>
                    </div>
                    <script>
                        function whCopy(id, btn) {
                            const el = document.getElementById(id);
                            el.select();
                            el.setSelectionRange(0, 99999);
                            navigator.clipboard.writeText(el.value);
                            const orig = btn.textContent;
                            btn.textContent = 'Copied!';
                            btn.style.background = '#059669';
                            setTimeout(() => {
                                btn.textContent = orig;
                                btn.style.background = '';
                            }, 1500);
                        }
                    </script>
                @elseif($provider === 'zoho' && $connection && ($webhook_auto_setup_status ?? null))
                    {{-- Zoho auto-setup result --}}
                    @if (($webhook_auto_setup_status ?? null) === 'success')
                        <div class="cc-card" style="background:#f0fdf4;border-color:#bbf7d0;">
                            <div style="display:flex;align-items:flex-start;gap:12px;">
                                <div
                                    style="width:32px;height:32px;border-radius:50%;background:#dcfce7;display:flex;align-items:center;justify-content:center;font-size:16px;color:#15803d;flex-shrink:0;">
                                    &#10003;</div>
                                <div>
                                    <div class="cc-card-title" style="color:#15803d;">Webhook configured automatically
                                    </div>
                                    <div style="font-size:13px;color:#166534;margin-top:4px;">
                                        Zoho Books is configured to notify this middleware when invoices are created.
                                        @if (!empty($webhook_id))
                                            &nbsp;·&nbsp; Webhook <code
                                                style="background:#dcfce7;padding:1px 5px;border-radius:4px;">{{ $webhook_id }}</code>
                                        @endif
                                        @if (!empty($workflow_id))
                                            &nbsp;·&nbsp; Workflow <code
                                                style="background:#dcfce7;padding:1px 5px;border-radius:4px;">{{ $workflow_id }}</code>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="cc-card" style="background:#fef2f2;border-color:#fecaca;">
                            <div style="display:flex;align-items:flex-start;gap:12px;">
                                <div
                                    style="width:32px;height:32px;border-radius:50%;background:#fecaca;display:flex;align-items:center;justify-content:center;font-size:16px;color:#dc2626;flex-shrink:0;">
                                    &#x21;</div>
                                <div>
                                    <div class="cc-card-title" style="color:#dc2626;">Automatic webhook setup failed
                                    </div>
                                    @if (!empty($webhook_auto_setup_error))
                                        <div style="font-size:13px;color:#991b1b;margin-top:4px;">
                                            {{ $webhook_auto_setup_error }}</div>
                                    @endif
                                    <div style="font-size:13px;color:#7f1d1d;margin-top:4px;">Reconnect
                                        {{ $providerLabel }} to retry, or configure the webhook manually.</div>
                                </div>
                            </div>
                        </div>
                    @endif
                @else
                    {{-- All other PMS — webhooks are automatic --}}
                    <div class="cc-card">
                        <div class="cc-card-title">Webhooks</div>
                        <div class="cc-card-desc">{{ $providerLabel }} webhooks are configured automatically when the
                            OAuth connection is complete.</div>
                        <div
                            style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:var(--cc-r-md);font-size:13px;color:#15803d;">
                            <span style="font-size:18px;">&#10003;</span>
                            No manual webhook setup required. Invoice events are received automatically.
                        </div>
                    </div>
                @endif

            </div>

            {{-- ── Email Settings tab ── --}}
            @php $emailConfig = $client->emailConfiguration; @endphp
            <div id="cc-panel-email" class="cc-tab-panel">

                @if ($provider === 'quickbooks')
                    {{-- From Name (read-only, derived from QB company) --}}
                    <div class="cc-card" style="background:var(--cc-bg-2,#f9fafb);border:1px solid var(--cc-border);">
                        <div class="cc-card-title">From Name</div>
                        <div class="cc-card-desc">Payment link emails are sent with this name in the
                            <strong>From</strong> field — pulled automatically from your QuickBooks company name.
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;margin-top:8px;flex-wrap:wrap;">
                            @if (!empty($company_name))
                                <input type="text" name ="company_name" value="{{ $company_name }}" disabled
                                    style="flex:1;max-width:320px;background:var(--cc-bg,#fff);color:var(--cc-text-2,#6b7280);cursor:not-allowed;opacity:.85;">
                                <span style="font-size:12px;color:var(--cc-text-3,#9ca3af);">Not editable — synced from
                                    QuickBooks</span>
                            @else
                                <span style="font-size:13px;color:var(--cc-text-3,#9ca3af);font-style:italic;">
                                    Company name not yet synced.
                                </span>
                            @endif

                            <form method="POST" action="{{ route('inbound.quickbooks.refresh-company-name') }}"
                                style="margin:0;">
                                @csrf
                                <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                                <button type="submit" class="button"
                                    style="font-size:12px;padding:6px 14px;display:inline-flex;align-items:center;gap:6px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="23 4 23 10 17 10"></polyline>
                                        <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                                    </svg>
                                    Refresh from QuickBooks
                                </button>
                            </form>
                        </div>
                    </div>
                @endif

                <form method="POST"
                    action="{{ route('inbound.clients.update-email-config', $client->pms_client_id) }}">
                    @csrf

                    {{-- Subject lines --}}
                    <div class="cc-card">
                        <div class="cc-card-title">Email Subject Lines</div>
                        <div class="cc-card-desc">
                            Customise the subject line for the initial payment link email and for resend emails when an
                            invoice amount changes.
                            Use <code
                                style="background:#f3f4f6;padding:1px 5px;border-radius:3px;font-size:12px;">{invoice_number}</code>,
                            <code
                                style="background:#f3f4f6;padding:1px 5px;border-radius:3px;font-size:12px;">{amount}</code>
                            as variables.
                        </div>

                        <div class="cc-field">
                            <label>Initial email subject</label>
                            <input type="text" name="subject_template" maxlength="500"
                                value="{{ old('subject_template', $emailConfig?->subject_template ?? 'Invoice #{invoice_number} – Payment Required') }}"
                                placeholder="Invoice #{invoice_number} – Payment Required">
                        </div>

                        <div class="cc-field" style="margin-bottom:0;">
                            <label>Resend / updated invoice subject</label>
                            <input type="text" name="subject_template_updated" maxlength="500"
                                value="{{ old('subject_template_updated', $emailConfig?->subject_template_updated ?? 'Updated: Invoice #{invoice_number} – Payment Required') }}"
                                placeholder="Updated: Invoice #{invoice_number} – Payment Required">
                        </div>
                    </div>

                    {{-- Brand colour --}}
                    <div class="cc-card">
                        <div class="cc-card-title">Brand Colour</div>
                        <div class="cc-card-desc">Used for the "Pay Now" button in payment link emails. Your company
                            logo is taken from the <strong>Company Logo</strong> section in the Connection tab.</div>

                        <div style="display:flex;align-items:center;gap:12px;">
                            <input type="color" id="primary-color-picker"
                                value="{{ old('primary_color', $emailConfig?->primary_color ?? '#2196F3') }}"
                                style="width:44px;height:36px;padding:2px;border:1px solid var(--cc-border);border-radius:var(--cc-r-sm);cursor:pointer;background:#fff;"
                                oninput="document.getElementById('primary-color-text').value=this.value">
                            <input type="text" id="primary-color-text" name="primary_color" maxlength="7"
                                value="{{ old('primary_color', $emailConfig?->primary_color ?? '#2196F3') }}"
                                placeholder="#2196F3" pattern="^#[0-9a-fA-F]{6}$" style="width:110px;"
                                oninput="if(/^#[0-9a-fA-F]{6}$/.test(this.value))document.getElementById('primary-color-picker').value=this.value">
                            <div id="color-preview"
                                style="width:80px;height:36px;border-radius:var(--cc-r-sm);border:1px solid var(--cc-border);background:{{ $emailConfig?->primary_color ?? '#2196F3' }};transition:background .2s;">
                            </div>
                            <span style="font-size:12px;color:var(--cc-text-3);">Preview</span>
                        </div>
                        <script>
                            document.getElementById('primary-color-text').addEventListener('input', function() {
                                if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
                                    document.getElementById('color-preview').style.background = this.value;
                                }
                            });
                            document.getElementById('primary-color-picker').addEventListener('input', function() {
                                document.getElementById('color-preview').style.background = this.value;
                            });
                        </script>
                    </div>

                    @if ($provider !== 'quickbooks')
                        {{-- From name --}}
                        <div class="cc-card">
                            <div class="cc-card-title">Sender Name</div>
                            <div class="cc-card-desc">The name customers see as the sender of payment emails
                                (e.g. "{{ $client?->client_name }}"). Leave blank to use the system default.</div>

                            <div class="cc-field" style="margin-bottom:0;">
                                <label>From name</label>
                                <input type="text" name="from_name" maxlength="255"
                                    value="{{ old('from_name', $emailConfig?->from_name ?? '') }}"
                                    placeholder="{{ $client?->client_name ?? 'Your Company Name' }}">
                            </div>
                        </div>
                    @endif

                    {{-- Reply-to --}}
                    <div class="cc-card">
                        <div class="cc-card-title">Reply-To Address</div>
                        <div class="cc-card-desc">When customers reply to payment emails, their reply goes to this
                            address. Leave blank to use the system default.</div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="cc-field" style="margin-bottom:0;">
                                <label>Reply-to email</label>
                                <input type="email" name="reply_to_email" maxlength="255"
                                    value="{{ old('reply_to_email', $emailConfig?->reply_to_email ?? '') }}"
                                    placeholder="billing@yourfirm.com">
                            </div>
                            <div class="cc-field" style="margin-bottom:0;">
                                <label>Reply-to name <span
                                        style="font-weight:400;color:var(--cc-text-3);">(optional)</span></label>
                                <input type="text" name="reply_to_name" maxlength="255"
                                    value="{{ old('reply_to_name', $emailConfig?->reply_to_name ?? '') }}"
                                    placeholder="Billing Team">
                            </div>
                        </div>
                    </div>

                    {{-- Body header / footer --}}
                    <div class="cc-card">
                        <div class="cc-card-title">Email Body Content</div>
                        <div class="cc-card-desc">
                            Optional text shown above and below the invoice summary in the email.
                            Available variables:
                            @foreach (['{merchant_name}', '{customer_name}', '{invoice_number}', '{amount}', '{due_date}', '{merchant_email}'] as $v)
                                <code
                                    style="background:#f3f4f6;padding:1px 5px;border-radius:3px;font-size:11px;">{{ $v }}</code>{{ !$loop->last ? '' : '' }}
                            @endforeach
                        </div>

                        <div class="cc-field">
                            <label>Header text <span style="font-weight:400;color:var(--cc-text-3);">(appears above
                                    invoice summary)</span></label>
                            <textarea name="body_header" rows="3" maxlength="5000"
                                style="width:100%;padding:8px 12px;border:1px solid var(--cc-border);border-radius:var(--cc-r-sm);font-size:13px;font-family:inherit;resize:vertical;background:#fafafa;"
                                placeholder="Dear {customer_name}, please find your invoice below.">{{ old('body_header', $emailConfig?->body_header ?? '') }}</textarea>
                        </div>

                        <div class="cc-field" style="margin-bottom:0;">
                            <label>Footer text <span style="font-weight:400;color:var(--cc-text-3);">(appears below
                                    invoice summary)</span></label>
                            <textarea name="body_footer" rows="3" maxlength="5000"
                                style="width:100%;padding:8px 12px;border:1px solid var(--cc-border);border-radius:var(--cc-r-sm);font-size:13px;font-family:inherit;resize:vertical;background:#fafafa;"
                                placeholder="Thank you for your business. Contact us at {merchant_email} with any questions.">{{ old('body_footer', $emailConfig?->body_footer ?? '') }}</textarea>
                        </div>
                    </div>

                    {{-- PDF attachment toggle — QuickBooks uses the merged Disabled/QB PDF/SimpHub PDF
                         selector on the Connection tab instead, since "disabled" there needs to set
                         this same attach_pdf flag alongside qb_use_native_pdf. --}}
                    @if ($provider !== 'quickbooks')
                        <div class="cc-card">
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                                <div>
                                    <div class="cc-card-title" style="margin-bottom:2px;">Attach Invoice PDF</div>
                                    <div style="font-size:13px;color:var(--cc-text-2);">When enabled, the invoice PDF is
                                        attached to the payment link email.</div>
                                </div>
                                @php $attachPdf = $emailConfig ? $emailConfig->attach_pdf : true; @endphp
                                <div
                                    style="display:flex;border:1px solid rgba(19,34,56,.15);border-radius:8px;overflow:hidden;font-size:12px;font-weight:700;">
                                    <input type="hidden" name="attach_pdf" id="attach-pdf-val"
                                        value="{{ $attachPdf ? '1' : '0' }}">
                                    <button type="button" id="attach-pdf-on"
                                        onclick="emailToggle('attach-pdf-val','attach-pdf-on','attach-pdf-off',true)"
                                        style="padding:5px 14px;border:none;cursor:pointer;font-family:inherit;{{ $attachPdf ? 'background:#132238;color:#fff;' : 'background:#fff;color:#9ca3af;' }}">ON</button>
                                    <button type="button" id="attach-pdf-off"
                                        onclick="emailToggle('attach-pdf-val','attach-pdf-on','attach-pdf-off',false)"
                                        style="padding:5px 14px;border:none;border-left:1px solid rgba(19,34,56,.15);cursor:pointer;font-family:inherit;{{ $attachPdf ? 'background:#fff;color:#9ca3af;' : 'background:#f1f5f9;color:#374151;' }}">OFF</button>
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- <div style="display:flex;justify-content:flex-end;">
                        <button type="submit" class="button primary" style="font-size:13px;padding:9px 24px;">Save
                            email settings</button>
                    </div> --}}
                    <div class="cc-action-bar">

                        <button type="button" id="previewEmailBtn" class="button button-light">

                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>

                            Preview Email

                        </button>

                        <button type="submit" class="button primary">

                            Save Email Settings

                        </button>

                    </div>

                    @error('primary_color')
                        <p style="font-size:12px;color:#dc2626;margin-top:6px;">{{ $message }}</p>
                    @enderror
                    @error('reply_to_email')
                        <p style="font-size:12px;color:#dc2626;margin-top:6px;">{{ $message }}</p>
                    @enderror
                </form>

                <script>
                    function emailToggle(valId, onId, offId, on) {
                        document.getElementById(valId).value = on ? '1' : '0';
                        document.getElementById(onId).style.background = on ? '#132238' : '#fff';
                        document.getElementById(onId).style.color = on ? '#fff' : '#9ca3af';
                        document.getElementById(offId).style.background = on ? '#fff' : '#f1f5f9';
                        document.getElementById(offId).style.color = on ? '#9ca3af' : '#374151';
                    }
                </script>



            </div>{{-- end cc-panel-email --}}

            {{-- Email Preview Modal --}}
            <div id="emailPreviewModal" class="cc-preview-overlay">

                <div class="cc-preview-modal">

                    <div class="cc-preview-header">

                        <div class="cc-preview-title">
                            Email Preview
                        </div>

                        <button type="button" id="closePreview" class="cc-preview-close">

                            ✕

                        </button>

                    </div>


                    <div id="emailPreviewContent" class="cc-preview-body">

                        <div style="background:#fff;padding:35px;border-radius:8px;">

                            <div id="previewCompanyName"
                                style="font-size:18px;font-weight:600;margin-bottom:15px;color:#333;">
                            </div>

                            <h2 id="previewSubject"></h2>

                            <p id="previewHeader"></p>

                            <table width="100%" cellspacing="0" cellpadding="12"
                                style="border-collapse:collapse;border:1px solid #ddd;margin:25px 0;">

                                <tr>
                                    <td><strong>Invoice Number</strong></td>
                                    <td>#1234</td>
                                </tr>

                                <tr>
                                    <td><strong>Amount Due</strong></td>
                                    <td>USD 00.00</td>
                                </tr>

                                <tr>
                                    <td><strong>Due Date</strong></td>
                                    <td>{{ now()->format('Y-m-d') }}</td>
                                </tr>

                            </table>

                            <button id="previewPayButton"
                                style="padding:12px 30px;color:#fff;border:none;border-radius:6px;cursor:pointer;">

                                Pay Now

                            </button>

                            <br><br>

                            <div id="previewFooter"></div>

                        </div>

                    </div>

                    <div class="cc-preview-footer">

                        <button type="button" id="closePreviewBottom" class="button">

                            Close

                        </button>

                    </div>

                </div>

            </div>


            {{-- ── Invoices tab ── --}}
            <div id="cc-panel-invoices" class="cc-tab-panel">

                <div class="cc-card">

                    <div class="cc-card-title">
                        Invoice List
                    </div>

                    <div class="cc-card-desc">
                        All invoices for this account.
                    </div>

                    @if ($invoices->count())

                        <table class="cc-table">
                            <thead>
                                <tr>
                                    <th>Invoice No.</th>
                                    <th>Recipient Email</th>
                                    <th>Amount</th>
                                    <th>Invoice Created Date</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                @forelse ($invoices as $invoice)
                                    <tr>

                                        <td>{{ $invoice->invoice_number ? '#' . $invoice->invoice_number : '-' }}</td>
                                        <td>{{ $invoice->recipient_email_list ?: '-' }}</td>

                                        <td>
                                            {{ !is_null($invoice->amount_cents) ? number_format($invoice->amount_cents / 100, 2) : '-' }}
                                        </td>

                                        <td>
                                            {{ optional($invoice->created_at)->format('Y-m-d') ?? '-' }}
                                        </td>

                                        <td>{{ ucfirst($invoice->status ?? 'Unknown') }}</td>
                                        <td>
                                            @if (!empty($invoice->recipient_emails))
                                                <form
                                                    action="{{ route('inbound.clients.resend-invoice', ['pms_client_id' => $client->pms_client_id, 'invoice' => $invoice->id]) }}"
                                                    method="POST">
                                                    @csrf

                                                    <input type="hidden" name="provider"
                                                        value="{{ $provider }}">

                                                    <button type="submit" class="button primary">
                                                        Resend
                                                    </button>
                                                </form>
                                            @else
                                                -
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center">
                                            No invoices found.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>

                        </table>
                    @else
                        <div class="alert alert-info">
                            No invoices found.
                        </div>

                    @endif

                </div>

            </div>

            <script>
                document.addEventListener("DOMContentLoaded", function() {

                    const previewModal = document.getElementById("emailPreviewModal");

                    const previewBtn = document.getElementById("previewEmailBtn");

                    const closeBtn = document.getElementById("closePreview");

                    const closeBottomBtn = document.getElementById("closePreviewBottom");


                    //==========================
                    // Open Preview
                    //==========================

                    previewBtn.addEventListener("click", function() {

                        loadEmailPreview();

                        previewModal.style.display = "flex";

                    });


                    //==========================
                    // Close Preview
                    //==========================

                    closeBtn.addEventListener("click", function() {

                        previewModal.style.display = "none";

                    });

                    closeBottomBtn.addEventListener("click", function() {

                        previewModal.style.display = "none";

                    });

                    window.addEventListener("click", function(e) {

                        if (e.target === previewModal) {

                            previewModal.style.display = "none";

                        }

                    });


                    //==========================
                    // Load Preview
                    //==========================

                    function loadEmailPreview() {

                        const subject = document.querySelector('[name="subject_template"]').value;

                        const header = document.querySelector('[name="body_header"]').value;

                        const footer = document.querySelector('[name="body_footer"]').value;

                        const replyEmail = document.querySelector('[name="reply_to_email"]').value;

                        const brandColor = document.querySelector('[name="primary_color"]').value;

                        const companyName = @json($company_name ?? '');

                        document.getElementById("previewCompanyName").textContent = companyName;

                        document.getElementById("previewSubject").textContent = subject;

                        document.getElementById("previewHeader").innerHTML =
                            header.replace(/\n/g, "<br>");

                        document.getElementById("previewFooter").innerHTML =
                            footer.replace(/\n/g, "<br>");
                        document.getElementById("previewPayButton").style.background =
                            brandColor;
                    }


                    //==========================
                    // Replace Variables
                    //==========================

                    function replaceVariables(text, data) {

                        if (!text) return "";

                        return text

                            .replaceAll("{customer_name}", data.customer_name)

                            .replaceAll("{invoice_number}", data.invoice_number)

                            .replaceAll("{amount}", data.amount)

                            .replaceAll("{due_date}", data.due_date)

                            .replaceAll("{merchant_name}", data.merchant_name)

                            .replaceAll("{merchant_email}", data.merchant_email);

                    }

                });
            </script>

        </x-inbound::client-config-layout>
    @else
        {{-- No client selected — show a minimal prompt --}}
        <div class="shell">
            <section class="panel">
                <p class="eyebrow">{{ $providerLabel }} Configuration</p>
                <h2>No client selected</h2>
                <p class="copy">Add a <code>pms_client_id</code> query parameter to manage a client.</p>
            </section>
        </div>
    @endif

</x-inbound::layouts.master>
