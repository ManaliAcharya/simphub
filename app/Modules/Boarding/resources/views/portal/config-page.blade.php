@php
    $boardingTabs = [
        ['id' => 'boarding', 'label' => 'ISO Boarding'],
    ];
@endphp

<x-inbound::client-config-layout :client="$client" provider-label="ISO Boarding" :tabs="$boardingTabs">

    <div id="cc-panel-boarding" class="cc-tab-panel">

        {{-- API Credentials --}}
        <div class="cc-card">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <h3 class="cc-card-title" style="margin:0;">API Credentials</h3>
                <a href="{{ route('inbound.clients.boarding.api-docs', $client->client_id) }}" class="cc-copy-btn" style="text-decoration:none;display:inline-block;">API Docs →</a>
            </div>
            <p class="cc-card-desc">Used to authenticate calls to <code>POST /api/v1/boarding/boarding-links</code>.</p>

            <div class="cc-field">
                <label>API Key</label>
                <div class="cc-copy-row">
                    <span class="cc-copy-val" id="api-key-box">{{ str_repeat('•', 52) }}</span>
                    <button type="button" class="cc-copy-btn" onclick="toggleKey()">Show</button>
                    <button type="button" class="cc-copy-btn" onclick="copyKey(this)">Copy</button>
                </div>
            </div>
        </div>

        {{-- Master Square Links --}}
        <div class="cc-card">
            <h3 class="cc-card-title">Master Square Links</h3>
            <p class="cc-card-desc">These are the real Square onboarding URLs merchants are redirected to after clicking a boarding link. If Square rotates a link, update it here — no code changes needed.</p>

            <form method="POST" action="{{ route('inbound.boarding.master-links') }}">
                @csrf
                @php
                    $rackRate = $client->masterLinks->firstWhere('tier', 'rack_rate');
                    $flatRate = $client->masterLinks->firstWhere('tier', 'flat_rate');
                @endphp
                <div class="cc-field">
                    <label for="rack_rate_url">Rack Rate — Square link</label>
                    <input type="url" id="rack_rate_url" name="rack_rate_url" value="{{ old('rack_rate_url', $rackRate->url ?? '') }}" placeholder="https://squareup.com/...">
                </div>
                <div class="cc-field">
                    <label for="flat_rate_url">Flat Rate — Square link</label>
                    <input type="url" id="flat_rate_url" name="flat_rate_url" value="{{ old('flat_rate_url', $flatRate->url ?? '') }}" placeholder="https://squareup.com/...">
                </div>
                <button type="submit" class="cc-copy-btn">Save master links</button>
            </form>
        </div>

        {{-- Webhooks --}}
        <div class="cc-card">
            <h3 class="cc-card-title">Webhooks</h3>
            <p class="cc-card-desc">We POST a <code>link.clicked</code> event to this URL the moment a merchant clicks their boarding link, before they're redirected to Square. See the <a href="{{ route('inbound.clients.boarding.api-docs', $client->client_id) }}">API docs</a> for the payload shape and signature verification.</p>

            <div class="cc-field">
                <label>Webhook Callback URL</label>
                <div id="wh-url-display" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    @if($client->webhook_url)
                        <span class="cc-copy-val" style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $client->webhook_url }}</span>
                    @else
                        <span style="font-size:13px;color:var(--cc-text-3);flex:1;">Not set — link.clicked events won't be delivered.</span>
                    @endif
                    <button type="button" class="cc-copy-btn" style="background:#f3f4f6;color:#374151;" onclick="toggleWhUrlEdit(true)">Edit</button>
                </div>
                <form id="wh-url-form" method="POST" action="{{ route('inbound.boarding.webhook-url') }}" style="display:none;margin-top:8px;">
                    @csrf
                    <div style="display:flex;align-items:center;gap:6px;">
                        <input type="url" name="webhook_url" value="{{ old('webhook_url', $client->webhook_url) }}"
                               placeholder="https://your-server.com/webhooks/isohub"
                               style="flex:1;padding:8px 12px;border:1px solid var(--cc-border);border-radius:var(--cc-r-sm);font:inherit;font-size:13px;min-width:0;">
                        <button type="submit" class="cc-copy-btn">Save</button>
                        <button type="button" class="cc-copy-btn" style="background:#f3f4f6;color:#374151;" onclick="toggleWhUrlEdit(false)">Cancel</button>
                    </div>
                </form>
            </div>

            <div class="cc-field" style="margin-top:14px;">
                <label>Webhook Secret</label>
                <div class="cc-copy-row">
                    <span class="cc-copy-val" id="wh-secret-box">{{ str_repeat('•', 40) }}</span>
                    <button type="button" class="cc-copy-btn" onclick="toggleSecret(this)">Show</button>
                    <button type="button" class="cc-copy-btn" onclick="copySecret(this)">Copy</button>
                </div>
            </div>
        </div>

        {{-- Merchants --}}
        <div class="cc-card">
            <h3 class="cc-card-title">Merchants ({{ $client->merchants->count() }})</h3>

            @if($client->merchants->isEmpty())
                <p class="cc-card-desc" style="margin-top:10px;">No boarding links generated yet. Call <code>POST /api/v1/boarding/boarding-links</code> with your API key to create one.</p>
            @else
                <hr class="cc-card-divider">
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;">
                        <thead>
                            <tr>
                                <th style="text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--cc-text-3);padding:8px 10px;border-bottom:1px solid var(--cc-border-light);">Merchant</th>
                                <th style="text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--cc-text-3);padding:8px 10px;border-bottom:1px solid var(--cc-border-light);">Ref</th>
                                <th style="text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--cc-text-3);padding:8px 10px;border-bottom:1px solid var(--cc-border-light);">Agent</th>
                                <th style="text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--cc-text-3);padding:8px 10px;border-bottom:1px solid var(--cc-border-light);">Tier</th>
                                <th style="text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--cc-text-3);padding:8px 10px;border-bottom:1px solid var(--cc-border-light);">Status</th>
                                <th style="text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--cc-text-3);padding:8px 10px;border-bottom:1px solid var(--cc-border-light);">Boarding Link</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($client->merchants as $merchant)
                            <tr>
                                <td style="padding:10px;font-size:13px;border-bottom:1px solid var(--cc-border-light);"><strong>{{ $merchant->merchant_name }}</strong></td>
                                <td style="padding:10px;font-size:12px;border-bottom:1px solid var(--cc-border-light);"><code style="font-size:11px;">{{ $merchant->merchant_ref }}</code></td>
                                <td style="padding:10px;font-size:13px;color:var(--cc-text-2);border-bottom:1px solid var(--cc-border-light);">{{ $merchant->agent_ref }}</td>
                                <td style="padding:10px;font-size:13px;border-bottom:1px solid var(--cc-border-light);">{{ str_replace('_', ' ', $merchant->tier) }}</td>
                                <td style="padding:10px;font-size:13px;border-bottom:1px solid var(--cc-border-light);">{{ str_replace('_', ' ', $merchant->status) }}</td>
                                <td style="padding:10px;font-size:11px;border-bottom:1px solid var(--cc-border-light);"><code style="font-size:11px;">{{ $merchant->boardingLink() }}</code></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

    </div>

</x-inbound::client-config-layout>

<script>
const RAW_KEY = @json($apiKey);
let apiKeyVisible = false;
function toggleKey() {
    apiKeyVisible = !apiKeyVisible;
    document.getElementById('api-key-box').textContent = apiKeyVisible ? RAW_KEY : '•'.repeat(52);
}
function copyKey(btn) {
    navigator.clipboard.writeText(RAW_KEY).then(() => {
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = 'Copy'; }, 2000);
    });
}

function toggleWhUrlEdit(show) {
    document.getElementById('wh-url-display').style.display = show ? 'none' : 'flex';
    document.getElementById('wh-url-form').style.display = show ? 'block' : 'none';
    if (show) document.querySelector('#wh-url-form input[name=webhook_url]').focus();
}

const RAW_SECRET = @json($webhookSecret);
let secretVisible = false;
function toggleSecret(btn) {
    secretVisible = !secretVisible;
    document.getElementById('wh-secret-box').textContent = secretVisible ? RAW_SECRET : '•'.repeat(40);
    btn.textContent = secretVisible ? 'Hide' : 'Show';
}
function copySecret(btn) {
    navigator.clipboard.writeText(RAW_SECRET).then(() => {
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = 'Copy'; }, 2000);
    });
}
</script>
