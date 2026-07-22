@php
    $boardingTabs = [
        ['id' => 'boarding', 'label' => 'ISO Boarding'],
    ];
@endphp

<x-inbound::client-config-layout :client="$client" provider-label="ISO Boarding" :tabs="$boardingTabs">

    <div id="cc-panel-boarding" class="cc-tab-panel">

        {{-- API Credentials --}}
        <div class="cc-card">
            <h3 class="cc-card-title">API Credentials</h3>
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
</script>
