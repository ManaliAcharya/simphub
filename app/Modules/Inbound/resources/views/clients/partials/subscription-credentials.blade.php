{{-- Subscription API credentials (Zoho CRM clients). Expects $client and $baseUrl. --}}
<div class="cc-card">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <div class="cc-card-title">API Credentials</div>
        <a href="{{ route('inbound.clients.subscription-api-docs', $client->pms_client_id) }}" target="_blank" rel="noopener"
           class="cc-copy-btn" style="text-decoration:none;display:inline-block;">API Docs &rarr;</a>
    </div>
    <div class="cc-card-desc">Share these with the client's CRM developer. The API key authenticates the subscribe and unsubscribe endpoints.</div>

    <div style="display:grid;gap:16px;">
        {{-- API key --}}
        <div>
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9ca3af;margin-bottom:5px;">API Key
                <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#6b7280;"> — use as Bearer token</span>
            </div>

            @if(session('subscription_api_key'))
                <div role="status" style="padding:10px 14px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;font-size:13px;color:#92400e;margin-bottom:8px;">
                    Copy this key now. For security it is not stored and will not be shown again.
                </div>
                <div class="cc-copy-row">
                    <code class="cc-copy-val" id="subscription-api-key-val" style="font-size:12px;word-break:break-all;">{{ session('subscription_api_key') }}</code>
                    <button type="button" class="cc-copy-btn" onclick="copyText('subscription-api-key-val', this)">Copy</button>
                </div>
            @elseif($client->subscription_api_key_hash)
                <div style="font-size:13px;color:#374151;">
                    Active key ending in <code>{{ $client->subscription_api_key_last4 }}</code>,
                    created {{ $client->subscription_api_key_created_at?->diffForHumans() }}.
                </div>
            @else
                <div style="font-size:13px;color:#6b7280;">No API key yet. Generate one to start the integration.</div>
            @endif

            <form method="POST" action="{{ route('inbound.clients.subscription-api-key', $client->pms_client_id) }}" style="margin-top:10px;"
                  @if($client->subscription_api_key_hash) onsubmit="return confirm('Generate a new key? The current key will stop working immediately.');" @endif>
                @csrf
                <button type="submit" class="button primary" style="font-size:13px;">
                    {{ $client->subscription_api_key_hash ? 'Regenerate API key' : 'Generate API key' }}
                </button>
            </form>
        </div>

        {{-- Base URL --}}
        <div>
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9ca3af;margin-bottom:5px;">Base URL</div>
            <div class="cc-copy-row">
                <code class="cc-copy-val" id="base-url-val">{{ $baseUrl }}/api/v1/subscriptions</code>
                <button type="button" class="cc-copy-btn" onclick="copyText('base-url-val', this)">Copy</button>
            </div>
        </div>
    </div>
</div>
