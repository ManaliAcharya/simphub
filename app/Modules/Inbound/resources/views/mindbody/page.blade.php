@php
    $tabs = [
        ['id' => 'connection', 'label' => 'Mindbody Connection'],
        ['id' => 'gateways',   'label' => 'Gateways'],
        ['id' => 'fees',       'label' => 'Fee Configuration'],
        ['id' => 'webhooks',   'label' => 'Webhooks'],
    ];
    $availableGateways = \Modules\Routing\Models\RoutingRule::query()
        ->where('is_active', true)->distinct()->pluck('gateway')
        ->filter()->map(fn($g) => strtoupper((string)$g))->values()->all();
    $isConnected = $site && $site->is_active;
@endphp

<x-inbound::layouts.master title="Mindbody Configuration">

@if($client)
<x-inbound::client-config-layout
    :client="$client"
    provider-label="Mindbody"
    :tabs="$tabs">

    {{-- ── Connection tab ── --}}
    <div id="cc-panel-connection" class="cc-tab-panel">

        @if(session('success'))
        <div class="cc-notice success" style="margin-bottom:14px;">{{ session('success') }}</div>
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
                    Mindbody
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
                        <div class="cc-card-title" style="color:#15803d;">Mindbody Connected</div>
                        <div style="font-size:12px;color:#6b7280;margin-top:1px;">Connected {{ $site->created_at->diffForHumans() }}</div>
                    </div>
                </div>
                <form method="POST" action="{{ route('inbound.mindbody.disconnect') }}" style="display:inline;">
                    @csrf
                    <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                    <button type="submit" class="button secondary" style="font-size:12px;padding:7px 16px;"
                            onclick="return confirm('Disconnect Mindbody? Webhooks will be removed.')">Disconnect</button>
                </form>
            </div>
            <hr class="cc-card-divider">
            <div style="display:flex;flex-wrap:wrap;gap:20px;">
                <div>
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:2px;">Site ID</div>
                    <div style="font-size:13px;font-weight:600;color:#1a1a2e;font-family:ui-monospace,monospace;">{{ $site->site_id }}</div>
                </div>
                @if($site->site_name)
                <div>
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:2px;">Site Name</div>
                    <div style="font-size:13px;font-weight:600;color:#1a1a2e;">{{ $site->site_name }}</div>
                </div>
                @endif
                <div>
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:2px;">Webhooks</div>
                    <div style="font-size:13px;font-weight:600;color:{{ $site->webhook_active ? '#15803d' : '#dc2626' }};">
                        {{ $site->webhook_active ? 'Active' : 'Inactive' }}
                    </div>
                </div>
                @if($site->staff_token_expires_at)
                <div>
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:2px;">Token Expires</div>
                    <div style="font-size:13px;font-weight:600;color:#1a1a2e;">{{ $site->staff_token_expires_at->format('M j, Y H:i') }}</div>
                </div>
                @endif
            </div>
        </div>
        @else
        <div class="cc-card" style="background:#fffbeb;border-color:#fde68a;">
            <div style="display:flex;align-items:center;gap:12px;">
                <span style="width:10px;height:10px;border-radius:50%;background:#f59e0b;flex-shrink:0;display:inline-block;"></span>
                <div>
                    <div class="cc-card-title" style="color:#92400e;">Mindbody not connected</div>
                    <div style="font-size:13px;color:#78350f;margin-top:2px;">Enter your Mindbody site credentials below to connect.</div>
                </div>
            </div>
        </div>
        @endif

        {{-- Connect / Update Credentials --}}
        <div class="cc-card">
            <div class="cc-card-title">{{ $isConnected ? 'Update Credentials' : 'Connect Mindbody' }}</div>
            <div class="cc-card-desc">
                Provide your Mindbody Site ID and a staff account with API access.
                Credentials are stored encrypted and used to issue auth tokens automatically.
            </div>
            <form method="POST" action="{{ route('inbound.mindbody.connect') }}">
                @csrf
                <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="cc-field">
                        <label>Site ID</label>
                        <input type="text" name="site_id"
                               value="{{ old('site_id', $site?->site_id) }}"
                               placeholder="e.g. -99 (sandbox)" required>
                    </div>
                    <div class="cc-field">
                        <label>Site Name <span style="font-weight:400;color:var(--cc-text-3);">(optional)</span></label>
                        <input type="text" name="site_name"
                               value="{{ old('site_name', $site?->site_name) }}"
                               placeholder="e.g. Serenity Spa">
                    </div>
                    <div class="cc-field">
                        <label>Staff Username</label>
                        <input type="text" name="staff_username"
                               value="{{ old('staff_username', $isConnected ? '••••••' : '') }}"
                               placeholder="Staff login email" required>
                    </div>
                    <div class="cc-field">
                        <label>Staff Password</label>
                        <input type="password" name="staff_password" placeholder="{{ $isConnected ? 'Leave blank to keep current' : 'Staff password' }}" {{ $isConnected ? '' : 'required' }}>
                    </div>
                </div>
                <button type="submit" class="button primary" style="font-size:13px;margin-top:4px;">
                    {{ $isConnected ? 'Update & Reconnect' : 'Connect Mindbody' }}
                </button>
            </form>
        </div>

        {{-- Payment Settings (only when connected) --}}
        @if($isConnected)
        <div class="cc-card">
            <div class="cc-card-title">Payment Settings</div>
            <div class="cc-card-desc">
                Select the custom payment method your business created in Mindbody and choose how payment links are scoped.
            </div>

            @if($loadError)
                <div class="cc-notice error">{{ $loadError }}</div>
            @elseif(!empty($customPaymentMethods))
            <form method="POST" action="{{ route('inbound.mindbody.settings') }}">
                @csrf
                <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                <input type="hidden" name="custom_payment_method_name" id="custom-pm-name" value="{{ $site->custom_payment_method_name }}">

                <div class="cc-field">
                    <label>Custom Payment Method</label>
                    <div class="cc-card-desc" style="margin-bottom:8px;font-size:12px;">
                        Create an "Online Payment" method in Mindbody → Settings → Payment Methods first.
                    </div>
                    <select name="custom_payment_method_id" required
                            onchange="document.getElementById('custom-pm-name').value = this.options[this.selectedIndex].textContent.trim()">
                        <option value="">Choose a custom payment method…</option>
                        @foreach($customPaymentMethods as $method)
                        @php $mid = $method['PaymentMethodId'] ?? $method['Id'] ?? ''; @endphp
                        <option value="{{ $mid }}"
                            @selected((string)$site->custom_payment_method_id === (string)$mid)>
                            {{ $method['PaymentMethodName'] ?? $method['Name'] ?? 'Unknown' }}
                        </option>
                        @endforeach
                    </select>
                </div>

                @if($site->custom_payment_method_name)
                <div style="font-size:13px;font-weight:600;color:#1a1a2e;margin-bottom:12px;">
                    Currently: {{ $site->custom_payment_method_name }}
                </div>
                @endif

                <div class="cc-field">
                    <label>Payment Link Mode</label>
                    <div class="cc-card-desc" style="margin-bottom:8px;font-size:12px;">
                        <strong>Per Sale</strong> — link covers only this sale's unpaid balance.<br>
                        <strong>Account Balance</strong> — link covers the client's total outstanding balance.
                    </div>
                    <select name="payment_link_mode" required>
                        <option value="per_sale"        @selected($site->payment_link_mode === 'per_sale')>Per Sale</option>
                        <option value="account_balance" @selected($site->payment_link_mode === 'account_balance')>Account Balance</option>
                    </select>
                </div>

                <button type="submit" class="button primary" style="font-size:13px;">Save settings</button>
            </form>
            @else
                <div class="cc-notice error">
                    No custom payment methods found. Create one in Mindbody → Settings → Payment Methods, then return here.
                </div>
            @endif
        </div>
        @endif

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

    {{-- ── Webhooks tab ── --}}
    <div id="cc-panel-webhooks" class="cc-tab-panel">
        <div class="cc-card">
            <div class="cc-card-title">Webhook Configuration</div>
            <div class="cc-card-desc">
                Mindbody sends events to this endpoint when a sale is created or an appointment is cancelled.
                The subscription is created automatically when you connect.
            </div>

            @if($isConnected)
            <div style="display:flex;flex-wrap:wrap;gap:20px;margin-top:12px;">
                <div>
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:4px;">Status</div>
                    <div style="font-size:13px;font-weight:600;color:{{ $site->webhook_active ? '#15803d' : '#dc2626' }};">
                        {{ $site->webhook_active ? '✓ Active' : '✗ Inactive' }}
                    </div>
                </div>
                @if($site->webhook_subscription_id)
                <div>
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:4px;">Subscription ID</div>
                    <div style="font-size:13px;font-weight:600;color:#1a1a2e;font-family:ui-monospace,monospace;">{{ $site->webhook_subscription_id }}</div>
                </div>
                @endif
            </div>

            <hr class="cc-card-divider">

            <div class="cc-field" style="margin-bottom:0;">
                <label>Webhook Endpoint URL</label>
                <div class="cc-copy-row">
                    <input type="text" id="mb-webhook-url" readonly
                           value="{{ rtrim(config('app.url'), '/') }}/api/v1/inbound/webhooks/mindbody/{{ $site->site_id }}"
                           style="flex:1;padding:8px 12px;border:1px solid var(--cc-border);border-radius:var(--cc-r-sm);font:inherit;font-size:13px;background:#f9fafb;">
                    <button type="button" class="cc-copy-btn"
                            onclick="const i=document.getElementById('mb-webhook-url');i.select();navigator.clipboard.writeText(i.value);this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1800);">
                        Copy
                    </button>
                </div>
            </div>

            <div style="margin-top:16px;">
                <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:8px;">Subscribed Events</div>
                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                    @foreach(['clientSale.created', 'appointmentBooking.cancelled', 'client.created', 'client.updated'] as $event)
                    <span style="padding:3px 10px;border-radius:99px;background:#eff6ff;color:#1e40af;font-size:12px;font-weight:600;">{{ $event }}</span>
                    @endforeach
                </div>
            </div>
            @else
            <div class="cc-notice" style="margin-top:12px;">
                Connect your Mindbody account on the Connection tab to activate webhooks.
            </div>
            @endif
        </div>
    </div>

</x-inbound::client-config-layout>
@else
<div style="padding:48px;text-align:center;color:#6b7280;">
    <p>No client selected. <a href="{{ route('inbound.clients.index') }}" style="color:#2563eb;">View all clients</a></p>
</div>
@endif

</x-inbound::layouts.master>
