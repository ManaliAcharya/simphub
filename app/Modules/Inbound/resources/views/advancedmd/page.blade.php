@php
    $tabs = [
        ['id' => 'connection', 'label' => 'AdvancedMD Connection'],
        ['id' => 'gateways',   'label' => 'Gateways'],
        ['id' => 'fees',       'label' => 'Fee Configuration'],
    ];
    $availableGateways = \Modules\Routing\Models\RoutingRule::query()
        ->where('is_active', true)->distinct()->pluck('gateway')
        ->filter()->map(fn($g) => strtoupper((string)$g))->values()->all();
    $isConnected = $practice && $practice->is_active;
@endphp

<x-inbound::layouts.master title="AdvancedMD Configuration">

@if($client)
<x-inbound::client-config-layout
    :client="$client"
    provider-label="AdvancedMD"
    :tabs="$tabs">

    {{-- ── Connection tab ── --}}
    <div id="cc-panel-connection" class="cc-tab-panel">

        @if(session('success') || ($success ?? null))
        <div class="cc-notice success" style="margin-bottom:14px;">{{ session('success') ?? $success }}</div>
        @endif
        @if($error ?? null)
        <div class="cc-notice error" style="margin-bottom:14px;">{{ $error }}</div>
        @endif

        {{-- Connected Services --}}
        <div class="cc-card">
            <div class="cc-card-title">Connected Services</div>
            <div class="cc-card-desc">Active PMS and payment gateway connections for this client.</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <div class="cc-conn-badge">
                    <span class="cc-conn-dot" style="{{ $isConnected ? '' : 'background:#f59e0b;' }}"></span>
                    AdvancedMD
                    @if(!$isConnected)<span style="font-weight:400;color:#92400e;"> · Not connected</span>@endif
                </div>
                @foreach($client->allowed_payment_gateways ?? [] as $gw)
                    @php $isPaused = in_array(strtoupper($gw), array_map('strtoupper', $client->paused_payment_gateways ?? [])); @endphp
                    <div class="cc-conn-badge" style="{{ $isPaused ? 'opacity:.5;' : '' }}">
                        <span class="cc-conn-dot" style="{{ $isPaused ? 'background:#9ca3af;' : '' }}"></span>
                        {{ strtoupper($gw) }}
                        @if($isPaused)<span style="font-weight:400;"> · Paused</span>@endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Connection Status --}}
        @if($isConnected)
        <div class="cc-card">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:36px;height:36px;border-radius:50%;background:#dcfce7;display:flex;align-items:center;justify-content:center;font-size:18px;color:#15803d;flex-shrink:0;">&#10003;</div>
                    <div>
                        <div class="cc-card-title" style="color:#15803d;">AdvancedMD Connected</div>
                        <div style="font-size:12px;color:#6b7280;margin-top:1px;">Connected {{ $practice->created_at->diffForHumans() }}</div>
                    </div>
                </div>
                <form method="POST" action="{{ route('inbound.advancedmd.disconnect') }}" style="display:inline;">
                    @csrf
                    <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                    <button type="submit" class="button secondary" style="font-size:12px;padding:7px 16px;color:#dc2626;border-color:#dc2626;"
                            onclick="return confirm('Disconnect AdvancedMD? The polling job will stop for this practice.')">Disconnect</button>
                </form>
            </div>
            <hr class="cc-card-divider">
            <div style="display:flex;flex-wrap:wrap;gap:20px;">
                <div>
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:2px;">Office Key</div>
                    <div style="font-size:13px;font-weight:600;color:#1a1a2e;font-family:ui-monospace,monospace;">{{ $practice->office_key }}</div>
                </div>
                <div>
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:2px;">App Name</div>
                    <div style="font-size:13px;font-weight:600;color:#1a1a2e;font-family:ui-monospace,monospace;">{{ $practice->app_name }}</div>
                </div>
                @if($practice->session_expires_at)
                <div>
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:2px;">Session Expires</div>
                    <div style="font-size:13px;font-weight:600;color:#1a1a2e;">{{ $practice->session_expires_at->format('M j, Y H:i') }}</div>
                </div>
                @endif
                @if($practice->last_polled_at)
                <div>
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:2px;">Last Polled</div>
                    <div style="font-size:13px;font-weight:600;color:#1a1a2e;">{{ $practice->last_polled_at->diffForHumans() }}</div>
                </div>
                @endif
                @if($practice->last_error)
                <div>
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:2px;">Last Error</div>
                    <div style="font-size:13px;font-weight:600;color:#dc2626;">{{ $practice->last_error }}</div>
                </div>
                @endif
            </div>
        </div>
        @else
        <div class="cc-card" style="background:#fffbeb;border-color:#fde68a;">
            <div style="display:flex;align-items:center;gap:12px;">
                <span style="width:10px;height:10px;border-radius:50%;background:#f59e0b;flex-shrink:0;display:inline-block;"></span>
                <div>
                    <div class="cc-card-title" style="color:#92400e;">AdvancedMD not connected</div>
                    <div style="font-size:13px;color:#78350f;margin-top:2px;">Enter your AdvancedMD API credentials below to connect.</div>
                </div>
            </div>
        </div>
        @endif

        {{-- Connect / Update Credentials --}}
        <div class="cc-card">
            <div class="cc-card-title">{{ $isConnected ? 'Update Credentials' : 'Connect AdvancedMD' }}</div>
            <div class="cc-card-desc">
                Provide your AdvancedMD office key, partner app name, and API login credentials.
                Credentials are stored encrypted. A session token is issued automatically and refreshed every 24 hours.
            </div>
            <form method="POST" action="{{ route('inbound.advancedmd.connect') }}">
                @csrf
                <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="cc-field">
                        <label>Office Key</label>
                        <input type="text" name="office_key"
                               value="{{ old('office_key', $practice?->office_key) }}"
                               placeholder="e.g. 990310" required>
                    </div>
                    <div class="cc-field">
                        <label>App Name</label>
                        <input type="text" name="app_name"
                               value="{{ old('app_name', $practice?->app_name) }}"
                               placeholder="Partner-assigned appname" required>
                    </div>
                    <div class="cc-field">
                        <label>API Username</label>
                        <input type="text" name="username"
                               value="{{ old('username') }}"
                               placeholder="{{ $isConnected ? 'Enter new username (required)' : 'API username' }}"
                               autocomplete="off" required>
                    </div>
                    <div class="cc-field">
                        <label>API Password</label>
                        <input type="password" name="password"
                               placeholder="{{ $isConnected ? 'Leave blank to keep current' : 'API password' }}"
                               autocomplete="new-password">
                    </div>
                </div>
                <button type="submit" class="button primary" style="font-size:13px;margin-top:4px;">
                    {{ $isConnected ? 'Update & Reconnect' : 'Connect AdvancedMD' }}
                </button>
            </form>
        </div>

        <x-inbound::notification-settings-form
            :client="$client"
            :form-action="route('inbound.clients.update-notification-settings', $client->pms_client_id)"
        />

    </div>{{-- end cc-panel-connection --}}

    {{-- ── Gateways tab ── --}}
    <div id="cc-panel-gateways" class="cc-tab-panel">
        <x-inbound::client-settings-card
            :client="$client"
            :available-gateways="$availableGateways"
            :show-left-col="false"
            :show-fees="false"
            :compact="true"
        />
    </div>

    {{-- ── Fee Configuration tab ── --}}
    <div id="cc-panel-fees" class="cc-tab-panel">
        <div class="cc-card">
            <div class="cc-card-title">Processing Fee Configuration</div>
            <x-inbound::fee-config-form
                :client="$client"
                :form-action="route('inbound.clients.update-fees', $client->pms_client_id)"
                btn-class="button primary"
            />
        </div>
    </div>

</x-inbound::client-config-layout>
@else
<div style="padding:48px;text-align:center;color:#6b7280;">
    <p>No client selected. <a href="{{ route('inbound.clients.index') }}" style="color:#2563eb;">View all clients</a></p>
</div>
@endif

</x-inbound::layouts.master>
