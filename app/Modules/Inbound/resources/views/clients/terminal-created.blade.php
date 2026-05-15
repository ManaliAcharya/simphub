<x-inbound::layouts.master>
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background:#f7f8fa; margin:0; }
        .check-icon { width:56px; height:56px; background:#ecfdf5; border-radius:50%; display:flex; align-items:center; justify-content:center; margin-bottom:16px; }
        .check-icon svg { color:#10b981; }
        .api-box { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px 20px; margin-top:20px; }
        .api-box label { display:block; font-size:12px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.06em; margin-bottom:8px; }
        .url-row { display:flex; align-items:center; gap:10px; }
        .url-row code { flex:1; background:#fff; border:1px solid #d1d5db; border-radius:8px; padding:10px 14px; font-size:13px; color:#111827; word-break:break-all; font-family:ui-monospace,monospace; }
        .copy-btn { flex-shrink:0; padding:10px 16px; background:#2563eb; color:#fff; border:none; border-radius:8px; font-size:13px; font-weight:500; cursor:pointer; transition:background .15s; }
        .copy-btn:hover { background:#1d4ed8; }
        .copy-btn.copied { background:#10b981; }
        .meta-row { display:flex; gap:32px; margin-top:20px; }
        .meta-item label { font-size:11px; font-weight:600; color:#9ca3af; text-transform:uppercase; letter-spacing:.06em; }
        .meta-item span { display:block; font-size:14px; color:#111827; margin-top:4px; font-weight:500; }
        .badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:12px; font-weight:600; background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; }
        .actions { margin-top:28px; padding-top:20px; border-top:1px solid #f3f4f6; }
        .btn { padding:10px 20px; border-radius:8px; font-size:14px; cursor:pointer; border:1px solid #d1d5db; background:#fff; font-weight:500; text-decoration:none; color:#374151; display:inline-block; }
        .notice { margin-top:16px; background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:12px 16px; font-size:13px; color:#92400e; }
    </style>

    <div class="shell">
        <section class="panel">
            <div class="check-icon">
                <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
            </div>

            <p class="eyebrow">Terminal Client Created</p>
            <h2>{{ $client->client_name }}</h2>
            <p class="sub">The client has been created with terminal payment mode. Share the API endpoint below — this is the URL that will be called externally with this merchant's ID.</p>

            <div class="api-box">
                <label>Terminal Charge API Endpoint</label>
                <div class="url-row">
                    <code id="api-url">{{ $apiUrl }}</code>
                    <button class="copy-btn" id="copy-btn" onclick="copyUrl()">Copy</button>
                </div>
            </div>

            <div class="meta-row">
                <div class="meta-item">
                    <label>Merchant ID</label>
                    <span><code style="font-size:13px;">{{ $client->pms_client_id }}</code></span>
                </div>
                <div class="meta-item">
                    <label>Terminal(s)</label>
                    <span>
                        @foreach ($client->allowed_terminals as $terminal)
                            <span class="badge">{{ strtoupper($terminal) }}</span>
                        @endforeach
                    </span>
                </div>
                <div class="meta-item">
                    <label>PMS</label>
                    <span>{{ $client->client_pms }}</span>
                </div>
            </div>

            <div class="notice">
                <strong>Method:</strong> POST &nbsp;|&nbsp; Send the merchant ID in the URL path. The request body is passed through to the terminal provider.
            </div>

            <div class="actions">
                <a class="btn" href="{{ route('inbound.clients.create') }}">Create Another Client</a>
            </div>
        </section>
    </div>

    <script>
        function copyUrl() {
            const url = document.getElementById('api-url').textContent.trim();
            navigator.clipboard.writeText(url).then(function () {
                const btn = document.getElementById('copy-btn');
                btn.textContent = 'Copied!';
                btn.classList.add('copied');
                setTimeout(function () {
                    btn.textContent = 'Copy';
                    btn.classList.remove('copied');
                }, 2000);
            });
        }
    </script>
</x-inbound::layouts.master>
