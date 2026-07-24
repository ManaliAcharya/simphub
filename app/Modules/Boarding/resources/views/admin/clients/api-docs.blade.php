<x-boarding::layouts.master title="API Docs — {{ $client->name }}">
<style>
    body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background:#f7f8fa; margin:0; }

    .page-header {
        background:#1a1a2e; padding:20px 32px;
        display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;
    }
    .page-header h1 { color:#fff; font-size:18px; font-weight:700; margin:0; }
    .breadcrumb { font-size:13px; color:rgba(255,255,255,.55); margin-bottom:4px; }
    .breadcrumb a { color:rgba(255,255,255,.75); text-decoration:none; }
    .breadcrumb a:hover { color:#fff; }

    /* ── Top nav tabs ── */
    .doc-nav { position:sticky; top:0; z-index:50; background:#fff; border-bottom:1px solid #e5e7eb; padding:0 32px; display:flex; gap:0; overflow-x:auto; }
    .doc-nav a { display:inline-block; padding:14px 18px; font-size:13px; font-weight:500; color:#6b7280; text-decoration:none; border-bottom:2px solid transparent; white-space:nowrap; transition:color .15s,border-color .15s; }
    .doc-nav a:hover { color:#111827; }
    .doc-nav a.active { color:#2563eb; border-bottom-color:#2563eb; }

    /* ── Credentials card ── */
    .client-card { margin:24px 32px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; display:grid; grid-template-columns:1fr 1fr; }
    @media(max-width:720px){ .client-card { grid-template-columns:1fr; } }
    .client-col { padding:20px 24px; display:flex; flex-direction:column; gap:18px; }
    .client-col-left { border-right:1px solid #e5e7eb; }
    @media(max-width:720px){ .client-col-left { border-right:none; border-bottom:1px solid #e5e7eb; } }
    .client-meta label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#9ca3af; display:block; margin-bottom:6px; }
    .copy-row { display:flex; align-items:center; gap:8px; }
    .copy-row code { font-family:ui-monospace,monospace; font-size:12px; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:6px; padding:5px 10px; color:#374151; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .copy-btn { padding:4px 10px; background:#2563eb; color:#fff; border:none; border-radius:6px; font-size:12px; font-weight:500; cursor:pointer; transition:background .15s; flex-shrink:0; }
    .copy-btn:hover { background:#1d4ed8; }
    .copy-btn.copied { background:#10b981; }
    .show-btn { padding:4px 10px; background:#f3f4f6; color:#374151; border:1px solid #e5e7eb; border-radius:6px; font-size:12px; font-weight:500; cursor:pointer; flex-shrink:0; }

    /* ── Content shell ── */
    .doc-body { padding:0 32px 60px; }
    .doc-section { padding-top:40px; }
    .doc-section h2 { font-size:18px; font-weight:700; color:#111827; margin:0 0 16px; padding-bottom:10px; border-bottom:1px solid #e5e7eb; }

    /* ── Tables ── */
    .doc-table { width:100%; border-collapse:collapse; font-size:13px; margin-bottom:24px; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; }
    .doc-table th { text-align:left; padding:9px 14px; background:#f9fafb; color:#6b7280; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; border-bottom:1px solid #e5e7eb; }
    .doc-table td { padding:10px 14px; border-bottom:1px solid #f3f4f6; color:#374151; vertical-align:top; font-size:13px; }
    .doc-table tr:last-child td { border-bottom:none; }
    .doc-table td code { font-family:ui-monospace,monospace; font-size:12px; background:#f3f4f6; padding:2px 6px; border-radius:4px; }
    .badge-req  { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; background:#fee2e2; color:#991b1b; }
    .badge-opt  { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; background:#f3f4f6; color:#6b7280; }

    /* ── Boarding flow steps ── */
    .flow-steps { list-style:none; padding:0; margin:0 0 8px; }
    .flow-steps li { display:flex; gap:14px; align-items:flex-start; padding:10px 0; border-bottom:1px solid #f3f4f6; font-size:13px; color:#374151; line-height:1.6; }
    .flow-steps li:last-child { border-bottom:none; }
    .step-num { flex-shrink:0; width:24px; height:24px; border-radius:50%; background:#2563eb; color:#fff; font-size:11px; font-weight:700; display:flex; align-items:center; justify-content:center; }
    .flow-steps li code { font-family:ui-monospace,monospace; font-size:12px; background:#f3f4f6; padding:1px 6px; border-radius:4px; }

    /* ── Endpoint cards ── */
    .endpoint-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:12px; }
    .ep-header { display:flex; align-items:center; gap:12px; padding:14px 20px; cursor:pointer; user-select:none; }
    .ep-header:hover { background:#f9fafb; }
    .method-badge { display:inline-flex; align-items:center; justify-content:center; padding:3px 10px; border-radius:6px; font-size:11px; font-weight:700; letter-spacing:.04em; flex-shrink:0; }
    .method-post { background:#fef3c7; color:#92400e; }
    .method-get  { background:#dcfce7; color:#166534; }
    .ep-url  { font-family:ui-monospace,monospace; font-size:13px; color:#111827; font-weight:500; }
    .ep-desc { font-size:13px; color:#6b7280; margin-left:4px; }
    .chevron { margin-left:auto; flex-shrink:0; color:#9ca3af; transition:transform .2s; }
    .endpoint-card.open .chevron { transform:rotate(180deg); }
    .ep-body { display:none; border-top:1px solid #f3f4f6; padding:24px; }
    .endpoint-card.open .ep-body { display:block; }
    .ep-desc-text { font-size:13px; color:#6b7280; margin:0 0 22px; line-height:1.65; }
    .section-label { font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.08em; margin:0 0 8px; }

    /* ── Code blocks ── */
    .code-block { background:#1e293b; border-radius:8px; padding:16px; overflow-x:auto; position:relative; margin-bottom:16px; }
    .code-block pre { margin:0; font-family:ui-monospace,monospace; font-size:12px; color:#e2e8f0; line-height:1.65; white-space:pre; }
    .copy-code { position:absolute; top:10px; right:10px; padding:3px 10px; background:#334155; color:#94a3b8; border:none; border-radius:5px; font-size:11px; cursor:pointer; transition:all .15s; }
    .copy-code:hover { background:#475569; color:#fff; }
    .copy-code.copied { background:#10b981; color:#fff; }
    .code-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; margin:0 0 8px; }
    .label-ok  { color:#10b981; }
    .label-err { color:#ef4444; }
    .label-req { color:#6b7280; }

    .event-code { font-family:ui-monospace,monospace; font-size:12px; }

    .info-box { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:14px 18px; font-size:13px; color:#1e40af; margin-bottom:20px; line-height:1.6; }
    .warn-box  { background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:14px 18px; font-size:13px; color:#92400e; margin-bottom:20px; line-height:1.6; }

    .status-4xx { display:inline-block; padding:2px 8px; border-radius:6px; font-size:12px; font-weight:600; background:#fee2e2; color:#991b1b; }

    @media(max-width:640px){
        .doc-nav { padding:0 16px; }
        .doc-body { padding-left:16px; padding-right:16px; }
        .client-card { margin-left:16px; margin-right:16px; }
    }
</style>

<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="{{ route('inbound.clients.boarding.show', $client->client_id) }}">{{ $client->name }}</a> / API Docs</div>
        <h1>Boarding API — {{ $client->name }}</h1>
    </div>
</div>

<div class="doc-nav">
    <a href="#auth" class="active" onclick="setActive(this)">Authentication</a>
    <a href="#flow" onclick="setActive(this)">Boarding flow</a>
    <a href="#endpoints" onclick="setActive(this)">Endpoints</a>
    <a href="#webhooks" onclick="setActive(this)">Webhooks</a>
    <a href="#errors" onclick="setActive(this)">Errors</a>
</div>

{{-- ── Credentials card ── --}}
<div class="client-card">
    <div class="client-col client-col-left">
        <div class="client-meta">
            <label>Client ID</label>
            <div class="copy-row">
                <code id="client-id-val">{{ $client->client_id }}</code>
                <button class="copy-btn" onclick="copyText('client-id-val', this)">Copy</button>
            </div>
        </div>
        <div class="client-meta">
            <label>Base URL</label>
            <div class="copy-row">
                <code id="base-url-val">{{ $baseUrl }}</code>
                <button class="copy-btn" onclick="copyText('base-url-val', this)">Copy</button>
            </div>
        </div>
    </div>
    <div class="client-col">
        <div class="client-meta">
            <label>API Key <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#b0b8c4;"> — use as Bearer token</span></label>
            <div class="copy-row">
                <code id="api-key-val">{{ str_repeat('•', 40) }}</code>
                <button class="show-btn" onclick="toggleApiKey(this)">Show</button>
                <button class="copy-btn" onclick="copyRawKey(this)">Copy</button>
            </div>
        </div>
        <div class="client-meta">
            <label>Webhook Callback URL</label>
            @if($client->webhook_url)
                <code id="webhook-url-val" style="display:block;font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:5px 10px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:6px;">{{ $client->webhook_url }}</code>
            @else
                <span style="font-size:13px;color:#9ca3af;">Not set — the client configures it from their portal.</span>
            @endif
        </div>
    </div>
</div>

<div class="doc-body">

    {{-- Authentication --}}
    <div class="doc-section" id="auth">
        <h2>Authentication</h2>
        <p style="font-size:13px;color:#6b7280;margin:0 0 16px;line-height:1.65;">
            All requests require a bearer token in the <code style="font-family:ui-monospace,monospace;font-size:12px;background:#f3f4f6;padding:2px 6px;border-radius:4px;">Authorization</code> header — your API key, not your Client ID.
        </p>
        <table class="doc-table">
            <tr><th>Header</th><th>Value</th><th>Required</th></tr>
            <tr>
                <td><code>Authorization</code></td>
                <td><code>Bearer &lt;your-api-key&gt;</code></td>
                <td><span class="badge-req">Required</span></td>
            </tr>
            <tr>
                <td><code>Content-Type</code></td>
                <td><code>application/json</code></td>
                <td><span class="badge-req">Required</span></td>
            </tr>
        </table>
    </div>

    {{-- Boarding flow --}}
    <div class="doc-section" id="flow">
        <h2>Boarding flow</h2>
        <ul class="flow-steps">
            <li><span class="step-num">1</span> Your agent calls <code>POST /api/v1/boarding/boarding-links</code> with the merchant's info, tier, and your agent reference.</li>
            <li><span class="step-num">2</span> SimpleHub registers the merchant attribution and returns a <code>boarding_link</code>.</li>
            <li><span class="step-num">3</span> You share that link with the merchant (copy/send).</li>
            <li><span class="step-num">4</span> The merchant clicks it — SimpleHub logs the click, locks the tier, and redirects them to the real Square onboarding page for your configured master link.</li>
            <li><span class="step-num">5</span> SimpleHub POSTs a <code>link.clicked</code> webhook to your callback URL, if configured.</li>
        </ul>
    </div>

    {{-- Endpoints --}}
    <div class="doc-section" id="endpoints">
        <h2>Endpoints</h2>

        {{-- POST /boarding-links --}}
        <div class="endpoint-card open" id="ep-boarding-links">
            <div class="ep-header" onclick="toggleEp('ep-boarding-links')">
                <span class="method-badge method-post">POST</span>
                <span class="ep-url">/api/v1/boarding/boarding-links</span>
                <span class="ep-desc">Register a merchant and get a boarding link</span>
                <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
            </div>
            <div class="ep-body">
                <p class="ep-desc-text">
                    Idempotent per <code>merchant_ref</code> + <code>tier</code>. Calling again with the same tier returns the existing link unchanged.
                    Calling with the <em>other</em> tier before the merchant has clicked supersedes the link (the old token stops resolving).
                    Once the merchant has clicked, the tier is locked — requesting the other tier returns <code>409</code>.
                    A master Square link must already be configured for the requested tier (client detail page), or this returns <code>422</code>.
                </p>

                <p class="section-label">Request Body</p>
                <table class="doc-table">
                    <tr><th>Field</th><th>Type</th><th>Description</th><th>Required</th></tr>
                    <tr><td><code>processor</code></td><td>string</td><td>Currently only <code>square</code>.</td><td><span class="badge-req">Required</span></td></tr>
                    <tr><td><code>scope</code></td><td>string</td><td>Currently only <code>merchant</code>.</td><td><span class="badge-req">Required</span></td></tr>
                    <tr><td><code>tier</code></td><td>string</td><td><code>rack_rate</code> or <code>flat_rate</code>.</td><td><span class="badge-req">Required</span></td></tr>
                    <tr><td><code>agent_ref</code></td><td>string</td><td>Your internal reference for the agent originating this boarding.</td><td><span class="badge-req">Required</span></td></tr>
                    <tr><td><code>merchant_ref</code></td><td>string</td><td>Your internal, unique merchant reference. Drives idempotency.</td><td><span class="badge-req">Required</span></td></tr>
                    <tr><td><code>merchant_info.name</code></td><td>string</td><td>Merchant business name.</td><td><span class="badge-req">Required</span></td></tr>
                    <tr><td><code>merchant_info.zip</code></td><td>string</td><td>Merchant ZIP code.</td><td><span class="badge-opt">Optional</span></td></tr>
                    <tr><td><code>created_by</code></td><td>string</td><td>Free-form attribution string, e.g. <code>agent:AGT-2291</code>.</td><td><span class="badge-opt">Optional</span></td></tr>
                </table>

                <p class="code-label label-req">Example Request</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>POST {{ $baseUrl }}/api/v1/boarding/boarding-links
Authorization: Bearer {{ $client->client_api_key }}
Content-Type: application/json

{
  "processor": "square",
  "scope": "merchant",
  "tier": "rack_rate",
  "agent_ref": "AGT-2291",
  "merchant_ref": "MER-10552",
  "merchant_info": { "name": "Acme Coffee LLC", "zip": "75201" },
  "created_by": "agent:AGT-2291"
}</pre>
                </div>

                <p class="code-label label-ok">201 — Created (or 200 if the merchant/tier already existed)</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "merchant_ref": "MER-10552",
  "processor": "square",
  "tier": "rack_rate",
  "status": "link_generated",
  "boarding_link": "{{ $baseUrl }}/sq/8f3a1c9d2e...",
  "created_at": "2026-07-17T14:20:00+00:00"
}</pre>
                </div>

                <p class="code-label label-err">409 — Tier Locked</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "error": "Boarding link already clicked; tier is locked and cannot be changed."
}</pre>
                </div>

                <p class="code-label label-err">422 — Master Link Not Configured</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "error": "No master square link configured for tier [flat_rate]. Configure it on the client portal before requesting boarding links."
}</pre>
                </div>
            </div>
        </div>

        {{-- GET /sq/{token} --}}
        <div class="endpoint-card" id="ep-sq-redirect">
            <div class="ep-header" onclick="toggleEp('ep-sq-redirect')">
                <span class="method-badge method-get">GET</span>
                <span class="ep-url">/sq/{token}</span>
                <span class="ep-desc">Merchant-facing click + redirect (not called by your integration)</span>
                <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
            </div>
            <div class="ep-body">
                <p class="ep-desc-text">
                    This is the <code>boarding_link</code> returned above — merchants click it directly, your integration never calls it.
                    SimpleHub logs the click, locks the tier, fires the <code>link.clicked</code> webhook, then 302-redirects the merchant to your configured master Square link for that tier.
                    An unknown or superseded token returns <code>404</code>; a revoked token returns <code>410</code>.
                </p>
            </div>
        </div>

        {{-- POST /boarding-links/revoke --}}
        <div class="endpoint-card" id="ep-revoke">
            <div class="ep-header" onclick="toggleEp('ep-revoke')">
                <span class="method-badge method-post">POST</span>
                <span class="ep-url">/api/v1/boarding/boarding-links/revoke</span>
                <span class="ep-desc">Bulk-revoke every link for one agent</span>
                <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
            </div>
            <div class="ep-body">
                <p class="ep-desc-text">
                    Call this when an agent is deactivated in ISOHub. Revokes <strong>every</strong> boarding link belonging to that <code>agent_ref</code> under your account —
                    regardless of whether the merchant already clicked through to Square. Already-revoked links are skipped (safe to call more than once).
                    Revoked tokens immediately start returning <code>410</code> at <code>GET /sq/{token}</code>.
                    Generating a new boarding-links request for a revoked merchant issues a fresh token and reactivates the record — the old token is not reused.
                </p>

                <p class="section-label">Query Parameter</p>
                <table class="doc-table">
                    <tr><th>Parameter</th><th>Type</th><th>Description</th><th>Required</th></tr>
                    <tr><td><code>agent_ref</code></td><td>string</td><td>The agent whose links should all be revoked.</td><td><span class="badge-req">Required</span></td></tr>
                </table>

                <p class="code-label label-req">Example Request</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>POST {{ $baseUrl }}/api/v1/boarding/boarding-links/revoke?agent_ref=AGT-2291
Authorization: Bearer {{ $client->client_api_key }}</pre>
                </div>

                <p class="code-label label-ok">200 — Revoked</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "agent_ref": "AGT-2291",
  "revoked_count": 2,
  "merchant_refs": ["MER-10552", "MER-99900"]
}</pre>
                </div>
            </div>
        </div>
    </div>

    {{-- Webhooks --}}
    <div class="doc-section" id="webhooks">
        <h2>Webhooks</h2>
        <p style="font-size:13px;color:#6b7280;margin:0 0 16px;line-height:1.65;">
            Fired the moment a merchant clicks their boarding link — POSTed to the Webhook Callback URL above. Verify the
            <code style="font-family:ui-monospace,monospace;font-size:12px;background:#f3f4f6;padding:2px 6px;border-radius:4px;">Middleware-Signature</code> header with your webhook secret.
            Retries: 0s, 30s, 2m, 10m, 1h, 6h, 24h — fails after 7 attempts.
        </p>
        <table class="doc-table">
            <tr><th>Event</th><th>Fires when</th></tr>
            <tr><td><span class="event-code">link.clicked</span></td><td>A merchant clicks their <code>boarding_link</code>, before the redirect to Square.</td></tr>
        </table>

        <p class="section-label" style="margin-top:24px;">Signature Verification</p>
        <p style="font-size:13px;color:#6b7280;margin:0 0 12px;line-height:1.65;">
            Every delivery includes a <code style="font-family:ui-monospace,monospace;font-size:12px;background:#f3f4f6;padding:2px 6px;border-radius:4px;">Middleware-Signature</code> header.
            Parse <code style="font-family:ui-monospace,monospace;font-size:12px;background:#f3f4f6;padding:2px 6px;border-radius:4px;">t</code> (Unix timestamp) and <code style="font-family:ui-monospace,monospace;font-size:12px;background:#f3f4f6;padding:2px 6px;border-radius:4px;">v1</code> (HMAC-SHA256 hex), then recompute the HMAC over <code style="font-family:ui-monospace,monospace;font-size:12px;background:#f3f4f6;padding:2px 6px;border-radius:4px;">&lt;t&gt;.&lt;raw_body&gt;</code> with your webhook secret.
        </p>

        <p class="code-label label-req">Example Payload (link.clicked)</p>
        <div class="code-block">
            <button class="copy-code" onclick="copyCode(this)">Copy</button>
            <pre>{
  "id": "evt_01hx8k2qf3...",
  "event": "link.clicked",
  "created_at": "2026-07-17T14:32:10+00:00",
  "data": {
    "merchant_ref": "MER-10552",
    "agent_ref": "AGT-2291",
    "processor": "square",
    "tier": "rack_rate",
    "status": "clicked",
    "clicked_at": "2026-07-17T14:32:10+00:00"
  }
}</pre>
        </div>

        <p class="code-label label-req">Verify Signature (PHP)</p>
        <div class="code-block">
            <button class="copy-code" onclick="copyCode(this)">Copy</button>
            <pre>$header = $_SERVER['HTTP_MIDDLEWARE_SIGNATURE'] ?? '';
preg_match('/t=(\d+),v1=([a-f0-9]+)/', $header, $m);
[$timestamp, $v1] = [$m[1] ?? '', $m[2] ?? ''];

$expected = hash_hmac('sha256', "{$timestamp}." . file_get_contents('php://input'), WEBHOOK_SECRET);

if (!hash_equals($expected, $v1)) {
    http_response_code(401); exit;
}
// Reject replays older than 5 minutes
if (abs(time() - (int)$timestamp) > 300) {
    http_response_code(400); exit;
}</pre>
        </div>

        <div class="info-box">
            If you don't configure a Webhook Callback URL, this event simply isn't sent — SimpleHub still records the click internally and shows it in the client portal and merchant status.
        </div>
    </div>

    {{-- Errors --}}
    <div class="doc-section" id="errors">
        <h2>Errors</h2>
        <table class="doc-table">
            <tr><th>Status</th><th>Meaning</th></tr>
            <tr><td><span class="status-4xx">401</span></td><td>Missing, malformed, or invalid/inactive bearer API key.</td></tr>
            <tr><td><span class="status-4xx">404</span></td><td><code>GET /sq/{token}</code> — unknown or superseded token.</td></tr>
            <tr><td><span class="status-4xx" style="background:#f3f4f6;color:#374151;">410</span></td><td><code>GET /sq/{token}</code> — the token was revoked via <code>POST /boarding-links/revoke</code>.</td></tr>
            <tr><td><span class="status-4xx">409</span></td><td>Tier is locked — merchant already clicked their link on a different tier.</td></tr>
            <tr><td><span class="status-4xx">422</span></td><td>Validation failure, or no master link configured yet for the requested tier.</td></tr>
        </table>
    </div>

</div>

<script>
    function toggleEp(id) {
        document.getElementById(id).classList.toggle('open');
    }
    function setActive(el) {
        document.querySelectorAll('.doc-nav a').forEach(a => a.classList.remove('active'));
        el.classList.add('active');
    }
    function copyText(id, btn) {
        navigator.clipboard.writeText(document.getElementById(id).textContent.trim()).then(function () {
            const original = btn.textContent;
            btn.textContent = 'Copied!';
            btn.classList.add('copied');
            setTimeout(function () { btn.textContent = original; btn.classList.remove('copied'); }, 2000);
        });
    }
    function copyCode(btn) {
        navigator.clipboard.writeText(btn.nextElementSibling.textContent.trim()).then(function () {
            btn.textContent = 'Copied!';
            btn.classList.add('copied');
            setTimeout(function () { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 2000);
        });
    }

    const RAW_API_KEY = @json($apiKey);
    let apiKeyVisible = false;
    function toggleApiKey(btn) {
        apiKeyVisible = !apiKeyVisible;
        document.getElementById('api-key-val').textContent = apiKeyVisible ? RAW_API_KEY : '•'.repeat(40);
        btn.textContent = apiKeyVisible ? 'Hide' : 'Show';
    }
    function copyRawKey(btn) {
        navigator.clipboard.writeText(RAW_API_KEY).then(function () {
            btn.textContent = 'Copied!';
            btn.classList.add('copied');
            setTimeout(function () { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 2000);
        });
    }

    // Highlight active nav on scroll
    const sections = ['auth','flow','endpoints','webhooks','errors'];
    window.addEventListener('scroll', function () {
        let current = 'auth';
        sections.forEach(function (id) {
            const el = document.getElementById(id);
            if (el && el.getBoundingClientRect().top <= 80) current = id;
        });
        document.querySelectorAll('.doc-nav a').forEach(function (a) {
            a.classList.toggle('active', a.getAttribute('href') === '#' + current);
        });
    });
</script>
</x-boarding::layouts.master>
