@php
$apiDocsTabs = [
    ['id' => 'credentials', 'label' => 'Credentials'],
    ['id' => 'gateways',    'label' => 'Gateways'],
    ['id' => 'fees',        'label' => 'Fee Configuration'],
    ['id' => 'apidocs',     'label' => 'API Docs'],
];
@endphp

<x-inbound::layouts.master>
<style>
    body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background:#f7f8fa; margin:0; }

    /* ── Top nav tabs ── */
    .doc-nav { position:sticky; top:0; z-index:50; background:#fff; border-bottom:1px solid #e5e7eb; padding:0 32px; display:flex; gap:0; }
    .doc-nav a { display:inline-block; padding:14px 18px; font-size:13px; font-weight:500; color:#6b7280; text-decoration:none; border-bottom:2px solid transparent; white-space:nowrap; transition:color .15s,border-color .15s; }
    .doc-nav a:hover { color:#111827; }
    .doc-nav a.active { color:#2563eb; border-bottom-color:#2563eb; }

    /* ── Page header ── */
    .doc-header { padding:32px 32px 0; }
    .doc-header .eyebrow { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:#9ca3af; margin:0 0 6px; }
    .doc-header h1 { font-size:26px; font-weight:700; color:#111827; margin:0 0 6px; display:flex; align-items:center; gap:10px; }
    .mode-badge { font-size:11px; font-weight:600; padding:3px 10px; border-radius:999px; background:#fef3c7; color:#92400e; border:1px solid #fde68a; }
    .doc-header .lead { font-size:14px; color:#6b7280; line-height:1.7; margin:0 0 28px; max-width:680px; }

    /* ── Client card ── */
    .client-card { margin:0 32px 32px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; display:grid; grid-template-columns:1fr 1fr; }
    .client-col { padding:20px 24px; display:flex; flex-direction:column; gap:20px; }
    .client-col-left { border-right:1px solid #e5e7eb; }
    .client-meta label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#9ca3af; display:block; margin-bottom:6px; }
    .copy-row { display:flex; align-items:center; gap:8px; }
    .copy-row code { font-family:ui-monospace,monospace; font-size:12px; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:6px; padding:5px 10px; color:#374151; }
    .copy-btn { padding:4px 10px; background:#2563eb; color:#fff; border:none; border-radius:6px; font-size:12px; font-weight:500; cursor:pointer; transition:background .15s; }
    .copy-btn:hover { background:#1d4ed8; }
    .copy-btn.copied { background:#10b981; }
    .env-toggle { display:flex; border:1px solid #e5e7eb; border-radius:6px; overflow:hidden; }
    .env-toggle span { padding:5px 14px; font-size:12px; font-weight:600; color:#6b7280; background:#f9fafb; cursor:default; }
    .env-toggle span.active { background:#eff6ff; color:#2563eb; }
    .gw-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 8px 3px 10px; border-radius:999px; font-size:12px; font-weight:600; background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; }
    .gw-remove-btn { background:none; border:none; cursor:pointer; color:#93c5fd; font-size:14px; line-height:1; padding:0; font-weight:700; transition:color .15s; }
    .gw-remove-btn:hover { color:#1d4ed8; }

    .add-gw-btn { margin-left:8px; padding:2px 10px; background:#ecfdf5; color:#059669; border:1px solid #6ee7b7; border-radius:6px; font-size:11px; font-weight:600; cursor:pointer; vertical-align:middle; transition:all .15s; }
    .add-gw-btn:hover { background:#d1fae5; }

    .add-gw-wrap { position:relative; display:inline-block; }
    .add-gw-pill-btn { display:inline-flex; align-items:center; gap:3px; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:600; background:#f9fafb; color:#6b7280; border:1px dashed #d1d5db; cursor:pointer; transition:all .15s; }
    .add-gw-pill-btn:hover { background:#eff6ff; color:#2563eb; border-color:#93c5fd; }
    .gw-dropdown { display:none; position:absolute; top:calc(100% + 6px); left:0; min-width:190px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 4px 16px rgba(0,0,0,.1); z-index:200; overflow:hidden; }
    .gw-dropdown.open { display:block; }
    .gw-dropdown-header { padding:7px 12px 5px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#9ca3af; background:#f9fafb; border-bottom:1px solid #f3f4f6; }
    .gw-dropdown-item { display:flex; align-items:center; justify-content:space-between; padding:9px 12px; font-size:13px; font-weight:500; color:#374151; cursor:pointer; transition:background .12s; border-top:1px solid #f3f4f6; }
    .gw-dropdown-item:first-of-type { border-top:none; }
    .gw-dropdown-item:hover:not(.gw-item-added) { background:#eff6ff; color:#2563eb; }
    .gw-dropdown-item.gw-item-added { color:#9ca3af; cursor:default; }
    .gw-dropdown-check { color:#10b981; font-size:14px; font-weight:700; }

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
    .badge-post { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; background:#fef9c3; color:#854d0e; }

    /* ── Payment flow steps ── */
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
    .schema-note { font-size:13px; color:#6b7280; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:14px 18px; }

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

    /* ── Webhook events ── */
    .event-code { font-family:ui-monospace,monospace; font-size:12px; }

    /* ── Error / info boxes ── */
    .info-box { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:14px 18px; font-size:13px; color:#1e40af; margin-bottom:20px; line-height:1.6; }
    .warn-box  { background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:14px 18px; font-size:13px; color:#92400e; margin-bottom:20px; line-height:1.6; }

    /* ── Status badges ── */
    .status-4xx { display:inline-block; padding:2px 8px; border-radius:6px; font-size:12px; font-weight:600; background:#fee2e2; color:#991b1b; }
    .status-5xx { display:inline-block; padding:2px 8px; border-radius:6px; font-size:12px; font-weight:600; background:#fef3c7; color:#92400e; }

    @media(max-width:640px){
        .doc-nav { padding:0 16px; overflow-x:auto; }
        .doc-header, .doc-body { padding-left:16px; padding-right:16px; }
        .client-card { margin-left:16px; margin-right:16px; }
    }

    /* ── Tooltips ── */
    .doc-tip-wrap { position:relative; display:inline-flex; align-items:center; }
    .doc-tip-icon { width:14px; height:14px; border-radius:50%; background:rgba(0,0,0,.08); color:#9ca3af;
        font-size:9px; font-weight:700; display:inline-flex; align-items:center; justify-content:center;
        margin-left:5px; cursor:help; flex-shrink:0; user-select:none; }
    .doc-tip-box { display:none; position:absolute; left:calc(100% + 8px); top:50%; transform:translateY(-50%);
        background:#1e293b; color:#e2e8f0; font-size:11.5px; font-weight:400; line-height:1.6;
        padding:10px 13px; border-radius:10px; width:230px; z-index:400;
        text-transform:none; letter-spacing:0; box-shadow:0 4px 18px rgba(0,0,0,.22); pointer-events:none; }
    .doc-tip-box::before { content:''; position:absolute; right:100%; top:50%; transform:translateY(-50%);
        border:5px solid transparent; border-right-color:#1e293b; }
    .doc-tip-wrap:hover .doc-tip-box { display:block; }
</style>
<x-inbound::client-config-layout
    :client="$client"
    provider-label="Custom CRM"
    :tabs="$apiDocsTabs">

    {{-- ── Credentials tab ── --}}
    <div id="cc-panel-credentials" class="cc-tab-panel">

        @if(session('success'))
        <div style="padding:12px 16px;background:#ecfdf5;border:1px solid #6ee7b7;border-radius:10px;font-size:13px;color:#065f46;margin-bottom:14px;">
            {{ session('success') }}
        </div>
        @endif

        {{-- API Credentials card --}}
        <div class="cc-card">
            <div class="cc-card-title">API Credentials</div>
            <div class="cc-card-desc">Use these values to authenticate your CRM with the payment middleware.</div>

            <div style="display:grid;gap:16px;">
                {{-- Client ID --}}
                <div>
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9ca3af;margin-bottom:5px;">Client ID
                        <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#b0b8c4;"> — use as Bearer token</span>
                    </div>
                    <div class="cc-copy-row">
                        <code class="cc-copy-val" id="client-id-val">{{ $client->pms_client_id }}</code>
                        <button class="cc-copy-btn" onclick="copyText('client-id-val', this)">Copy</button>
                    </div>
                </div>

                {{-- Base URL --}}
                <div>
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9ca3af;margin-bottom:5px;">Base URL</div>
                    <div class="cc-copy-row">
                        <code class="cc-copy-val" id="base-url-val">{{ $baseUrl }}</code>
                        <button class="cc-copy-btn" onclick="copyText('base-url-val', this)">Copy</button>
                    </div>
                </div>

                @if($client->webhook_secret)
                <hr class="cc-card-divider" style="margin:4px 0;">

                {{-- Webhook Secret --}}
                <div>
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9ca3af;margin-bottom:5px;">Webhook Secret</div>
                    <div class="cc-copy-row">
                        <code class="cc-copy-val" id="webhook-secret-val" style="font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:340px;">{{ $client->webhook_secret }}</code>
                        <button class="cc-copy-btn" onclick="copyText('webhook-secret-val', this)">Copy</button>
                    </div>
                    <div style="font-size:12px;color:#9ca3af;margin-top:4px;">Verify the <code style="font-family:ui-monospace,monospace;font-size:11px;background:#f3f4f6;padding:1px 5px;border-radius:4px;">Middleware-Signature</code> header on incoming webhooks.</div>
                </div>

                {{-- Webhook URL --}}
                <div>
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9ca3af;margin-bottom:5px;">Webhook Callback URL</div>
                    <div id="whurl-display" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        @if($client->webhook_url)
                            <code class="cc-copy-val" style="flex:1;min-width:0;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $client->webhook_url }}</code>
                        @else
                            <span style="font-size:13px;color:#9ca3af;flex:1;">Not set</span>
                        @endif
                        <button type="button" class="cc-copy-btn" style="background:#f3f4f6;color:#374151;" onclick="toggleWhUrlEdit(true)">Edit</button>
                    </div>
                    <form id="whurl-form" method="POST"
                          action="{{ route('inbound.clients.update-webhook-url', $client->pms_client_id) }}"
                          style="display:none;margin-top:8px;">
                        @csrf
                        <div style="display:flex;align-items:center;gap:6px;">
                            <input type="url" name="webhook_url" value="{{ $client->webhook_url }}"
                                   placeholder="https://your-server.com/webhook"
                                   style="flex:1;padding:8px 12px;border:1px solid var(--cc-border);border-radius:var(--cc-r-sm);font:inherit;font-size:13px;min-width:0;">
                            <button type="submit" class="cc-copy-btn" style="white-space:nowrap;">Save</button>
                            <button type="button" class="cc-copy-btn" style="background:#f3f4f6;color:#374151;white-space:nowrap;" onclick="toggleWhUrlEdit(false)">Cancel</button>
                        </div>
                    </form>
                    <div style="font-size:12px;color:#9ca3af;margin-top:4px;">Payment events (<code style="font-family:ui-monospace;font-size:11px;background:#f3f4f6;padding:1px 4px;border-radius:3px;">invoice.paid</code>, <code style="font-family:ui-monospace;font-size:11px;background:#f3f4f6;padding:1px 4px;border-radius:3px;">refund.completed</code>) are POSTed here.</div>
                </div>
                @endif
            </div>
        </div>

        {{-- Environment card --}}
        <div class="cc-card">
            <div class="cc-card-title">Environment</div>
            <div class="cc-card-desc">This client is currently in test mode. No live charges are processed.</div>
            <div style="display:inline-flex;border:1px solid var(--cc-border);border-radius:var(--cc-r-sm);overflow:hidden;font-size:13px;font-weight:600;">
                <span style="padding:7px 18px;background:var(--cc-primary-light);color:var(--cc-primary);border-right:1px solid var(--cc-primary-border);">Test</span>
                <span style="padding:7px 18px;background:#f9fafb;color:#9ca3af;">Live</span>
            </div>
        </div>

    </div>{{-- end credentials tab --}}

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
            <div class="cc-card-desc">Configure processing fees passed to customers at checkout.</div>
            <x-inbound::fee-config-form
                :client="$client"
                :form-action="route('inbound.clients.update-fees', $client->pms_client_id)"
                btn-class="button primary"
            />
        </div>
    </div>

    {{-- ── API Docs tab ── --}}
    <div id="cc-panel-apidocs" class="cc-tab-panel">
    <section class="panel" style="padding:0;overflow:hidden;">
    {{-- Internal doc-nav: offset top to sit below the sticky shell topnav --}}
    <div class="doc-nav" style="top:52px;">
        <a href="#auth" class="active" onclick="setActive(this)">Authentication</a>
        <a href="#flow" onclick="setActive(this)">Payment flow</a>
        <a href="#endpoints" onclick="setActive(this)">Endpoints</a>
        <a href="#webhooks" onclick="setActive(this)">Webhooks</a>
        <a href="#errors" onclick="setActive(this)">Errors</a>
        <a href="#sandbox" onclick="setActive(this)">Sandbox</a>
    </div>


<div class="doc-body">

    {{-- Authentication --}}
    <div class="doc-section" id="auth">
        <h2>Authentication</h2>
        <p style="font-size:13px;color:#6b7280;margin:0 0 16px;line-height:1.65;">
            All requests require a bearer token in the <code style="font-family:ui-monospace,monospace;font-size:12px;background:#f3f4f6;padding:2px 6px;border-radius:4px;">Authorization</code> header.
            Use your Client ID as the token. Test and live environments are separate.
        </p>
        <table class="doc-table">
            <tr><th>Header</th><th>Value</th><th>Required</th></tr>
            <tr>
                <td><code>Authorization</code></td>
                <td><code>Bearer &lt;your-client-id&gt;</code></td>
                <td><span class="badge-req">Required</span></td>
            </tr>
            <tr>
                <td><code>Content-Type</code></td>
                <td><code>application/json</code></td>
                <td><span class="badge-req">Required</span></td>
            </tr>
        </table>
    </div>

    {{-- Payment flow --}}
    <div class="doc-section" id="flow">
        <h2>Payment flow</h2>
        <ul class="flow-steps">
            <li><span class="step-num">1</span> Your CRM calls <code>POST /api/v1/invoices</code> with the amount, customer details, and redirect URLs.</li>
            <li><span class="step-num">2</span> Middleware creates the invoice and returns a <code>payment_url</code>.</li>
            <li><span class="step-num">3</span> Your CRM redirects the payer's browser to <code>payment_url</code>.</li>
            <li><span class="step-num">4</span> Payer enters bank or card details on the hosted page. We process via Selected Payment Gateway.</li>
            <li><span class="step-num">5</span> Payer is redirected to your <code>success_redirect_url</code> or <code>cancel_redirect_url</code>.</li>
            <li><span class="step-num">6</span> Middleware POSTs an <code>invoice.paid</code>, <code>invoice.failed</code>, or <code>ach.settled</code> webhook to your callback URL.</li>
        </ul>
    </div>

    {{-- Endpoints --}}
    <div class="doc-section" id="endpoints">
        <h2>Endpoints</h2>

        {{-- POST /invoices --}}
        <div class="endpoint-card open" id="ep-invoices">
            <div class="ep-header" onclick="toggleEp('ep-invoices')">
                <span class="method-badge method-post">POST</span>
                <span class="ep-url">/api/v1/invoices</span>
                <span class="ep-desc">Create an invoice and receive a hosted payment URL</span>
                <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
            </div>
            <div class="ep-body">
                <p class="ep-desc-text">
                    Creates an invoice record and returns a hosted <code>payment_url</code> your CRM should redirect the payer to.
                    Gateway selection happens automatically at checkout time based on routing rules configured for your account — no gateway field is required here.
                </p>

                <p class="section-label">Request Body</p>
                <table class="doc-table">
                    <tr><th>Field</th><th>Type</th><th>Description</th><th>Required</th></tr>
                    <tr><td><code>amount_cents</code></td><td>integer</td><td>Amount in smallest currency unit. <code>150000</code> = $1,500.00</td><td><span class="badge-req">Required</span></td></tr>
                    <tr><td><code>currency</code></td><td>string</td><td>ISO 4217. <code>USD</code> only in v1.</td><td><span class="badge-req">Required</span></td></tr>
                    <tr><td><code>invoice_number</code></td><td>string</td><td>Your invoice reference. 1–64 chars, unique per client.</td><td><span class="badge-req">Required</span></td></tr>
                    <tr><td><code>description</code></td><td>string</td><td>Shown on the hosted payment page.</td><td><span class="badge-opt">Optional</span></td></tr>
                    <tr><td><code>customer</code></td><td>object</td><td><code>name</code>, <code>email</code>, <code>phone</code></td><td><span class="badge-req">Required</span></td></tr>
                    <tr><td><code>success_redirect_url</code></td><td>string</td><td>HTTPS only. Payer lands here on success.</td><td><span class="badge-req">Required</span></td></tr>
                    <tr><td><code>cancel_redirect_url</code></td><td>string</td><td>HTTPS only. Payer lands here on cancel.</td><td><span class="badge-req">Required</span></td></tr>
                    <tr><td><code>metadata</code></td><td>object</td><td>Up to 20 key/value pairs returned on webhooks.</td><td><span class="badge-opt">Optional</span></td></tr>
                </table>

                <p class="code-label label-req">Example Request</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>POST {{ $baseUrl }}/api/v1/invoices
Authorization: Bearer {{ $client->pms_client_id }}
Content-Type: application/json

{
  "amount_cents": 150000,
  "currency": "USD",
  "invoice_number": "INV-2026-00123",
  "description": "Legal services — May 2026",
  "customer": {
    "name": "John Smith",
    "email": "john@example.com",
    "phone": "+13125551234"
  },
  "success_redirect_url": "https://yourcrm.com/paid",
  "cancel_redirect_url": "https://yourcrm.com/cancelled",
  "metadata": { "matter_id": "M-9091" }
}</pre>
                </div>

                <p class="code-label label-ok">201 — Created</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "invoice_id": "inv_01HX8K2QF3...",
  "status": "pending",
  "payment_url": "{{ $baseUrl }}/pay/inv_01HX8K2QF3...",
  "expires_at": "2026-06-13T18:00:00Z"
}</pre>
                </div>
            </div>
        </div>

        {{-- GET /invoices/{id} --}}
        <div class="endpoint-card" id="ep-invoice-get">
            <div class="ep-header" onclick="toggleEp('ep-invoice-get')">
                <span class="method-badge method-get">GET</span>
                <span class="ep-url">/api/v1/invoices/{invoice_id}</span>
                <span class="ep-desc">Retrieve invoice status</span>
                <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
            </div>
            <div class="ep-body">
                <p class="ep-desc-text">Returns the current status and details of an invoice created under your account.</p>

                <p class="section-label">URL Parameter</p>
                <table class="doc-table">
                    <tr><th>Parameter</th><th>Type</th><th>Description</th><th>Required</th></tr>
                    <tr><td><code>invoice_id</code></td><td>string</td><td>The UUID returned when the invoice was created.</td><td><span class="badge-req">Required</span></td></tr>
                </table>

                <p class="code-label label-req">Example Request</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>GET {{ $baseUrl }}/api/v1/invoices/{invoice_id}
Authorization: Bearer {{ $client->pms_client_id }}</pre>
                </div>

                <p class="code-label label-ok">200 — Success</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "invoice_id": "uuid",
  "invoice_number": "INV-2026-001",
  "status": "pending",
  "amount_cents": 150000,
  "currency": "USD",
  "description": "Legal services — May 2026",
  "customer": {
    "name": "John Smith",
    "email": "john@example.com",
    "phone": "+13125551234"
  },
  "metadata": { "matter_id": "M-9091" },
  "payment_url": "{{ $baseUrl }}/pay/...",
  "expires_at": "2026-06-18T08:00:00+00:00",
  "created_at": "2026-05-18T08:00:00+00:00"
}</pre>
                </div>

                <p class="code-label label-err">404 — Not Found</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "error": { "code": "not_found", "message": "Invoice not found." }
}</pre>
                </div>
            </div>
        </div>

        {{-- POST /invoices/{id}/cancel --}}
        <div class="endpoint-card" id="ep-invoice-cancel" style="display:none;">
            <div class="ep-header" onclick="toggleEp('ep-invoice-cancel')">
                <span class="method-badge method-post">POST</span>
                <span class="ep-url">/api/v1/invoices/{invoice_id}/cancel</span>
                <span class="ep-desc">Cancel an unpaid invoice</span>
                <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
            </div>
            <div class="ep-body">
                <p class="ep-desc-text">
                    Cancels a <strong>pending (unpaid)</strong> invoice and invalidates its payment link. No gateway call is made.
                    If the invoice has a captured transaction, use <code>POST /api/v1/transactions/{transaction_id}/cancel</code> instead to void it at the gateway.
                </p>

                <p class="section-label">URL Parameter</p>
                <table class="doc-table">
                    <tr><th>Parameter</th><th>Type</th><th>Description</th><th>Required</th></tr>
                    <tr><td><code>invoice_id</code></td><td>string</td><td>UUID of the invoice to cancel.</td><td><span class="badge-req">Required</span></td></tr>
                </table>

                <p class="code-label label-req">Example Request</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>POST {{ $baseUrl }}/api/v1/invoices/{invoice_id}/cancel
Authorization: Bearer {{ $client->pms_client_id }}</pre>
                </div>

                <p class="code-label label-ok">200 — Cancelled</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "invoice_id": "uuid",
  "status": "cancelled"
}</pre>
                </div>

                <p class="code-label label-err">400 — Has Captured Transaction</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "error": {
    "code": "validation_error",
    "message": "This invoice has a captured transaction. Use POST /v1/transactions/{transaction_id}/cancel to void it at the gateway."
  }
}</pre>
                </div>
            </div>
        </div>

        {{-- POST /transactions/{id}/cancel --}}
        <div class="endpoint-card" id="ep-txn-cancel">
            <div class="ep-header" onclick="toggleEp('ep-txn-cancel')">
                <span class="method-badge method-post">POST</span>
                <span class="ep-url">/api/v1/transactions/{transaction_id}/cancel</span>
                <span class="ep-desc">Void a captured transaction at the gateway</span>
                <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
            </div>
            <div class="ep-body">
                <p class="ep-desc-text">
                    Voids a <code>CAPTURED</code> transaction directly at the payment gateway, then cancels the associated invoice.
                    The gateway decides whether the void is possible — if the transaction has already settled, use the refund API instead.
                    Use the <code>transaction_id</code> from <code>GET /api/v1/transactions</code>.
                </p>

                <p class="section-label">Gateway Support</p>
                <table class="doc-table" style="margin-bottom:16px;">
                    <tr><th>Gateway</th><th>Void supported</th><th>Notes</th></tr>
                    <tr>
                        <td><code>fluidpay</code></td>
                        <td><span style="color:#059669;font-weight:700;">Yes</span></td>
                        <td>Void succeeds only while the transaction is <code>pending_settlement</code>. Once the daily batch closes it returns <code>void_declined</code> — use refund instead.</td>
                    </tr>
                    <tr>
                        <td><code>paya</code></td>
                        <td><span style="color:#dc2626;font-weight:700;">No</span></td>
                        <td>Paya ACH has no void operation. This endpoint always returns <code>void_declined</code> for Paya transactions. Use the refund API.</td>
                    </tr>
                </table>

                <div class="warn-box">
                    <strong>Paya ACH transactions cannot be voided.</strong>
                    If your transaction was processed via Paya, calling this endpoint will always return <code>400 void_declined</code>.
                    Use <code>POST /api/v1/refunds</code> to return funds to the customer's bank account (2–3 business days).
                </div>

                <p class="section-label">URL Parameter</p>
                <table class="doc-table">
                    <tr><th>Parameter</th><th>Type</th><th>Description</th><th>Required</th></tr>
                    <tr>
                        <td><code>transaction_id</code></td>
                        <td>string (UUID)</td>
                        <td>
                            <strong>Middleware transaction ID</strong> — the <code>transaction_id</code> field from <code>GET /api/v1/transactions</code> or the <code>invoice.paid</code> webhook payload.
                            <br><span style="color:#dc2626;">Not the gateway's own transaction reference (<code>gateway_txn_id</code>).</span>
                        </td>
                        <td><span class="badge-req">Required</span></td>
                    </tr>
                </table>

                <p class="code-label label-req">Example Request</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>POST {{ $baseUrl }}/api/v1/transactions/{transaction_id}/cancel
Authorization: Bearer {{ $client->pms_client_id }}</pre>
                </div>

                <p class="code-label label-ok">200 — Voided (FluidPay only)</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "transaction_id": "uuid",
  "invoice_id": "uuid",
  "status": "voided"
}</pre>
                </div>

                <p class="code-label label-err">400 — Paya / Already Settled</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "error": {
    "code": "void_declined",
    "message": "The transaction has already been settled at the gateway and cannot be voided. Use the refund API instead.",
    "gateway_message": "Void is not supported for Paya ACH transactions."
  }
}</pre>
                </div>

                <p class="code-label label-err">400 — Wrong Status</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "error": { "code": "validation_error", "message": "Transaction cannot be voided in its current status (VOIDED)." }
}</pre>
                </div>
            </div>
        </div>

        {{-- POST /refunds --}}
        <div class="endpoint-card" id="ep-refunds">
            <div class="ep-header" onclick="toggleEp('ep-refunds')">
                <span class="method-badge method-post">POST</span>
                <span class="ep-url">/api/v1/refunds</span>
                <span class="ep-desc">Issue a refund against a captured transaction</span>
                <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
            </div>
            <div class="ep-body">
                <p class="ep-desc-text">
                    Issues a full or partial refund against a previously <code>CAPTURED</code> transaction.
                    The gateway credit is submitted immediately and a new <code>credit</code> transaction record is created.
                    Partial refunds are supported; multiple partial refunds can be issued until the full amount is recovered.
                </p>

                <p class="section-label">Gateway Behaviour</p>
                <table class="doc-table" style="margin-bottom:16px;">
                    <tr><th>Gateway</th><th>How refund works</th><th>Timeline</th></tr>
                    <tr>
                        <td><code>fluidpay</code></td>
                        <td>Linked refund — references original transaction ID at the gateway.</td>
                        <td>3–5 business days to cardholder</td>
                    </tr>
                    <tr>
                        <td><code>paya</code></td>
                        <td>Standalone ACH credit — no gateway-level link to the original debit. This is the <strong>only reversal option</strong> for Paya (void is not supported).</td>
                        <td>2–3 business days to bank account</td>
                    </tr>
                </table>

                <p class="section-label">Request Body</p>
                <table class="doc-table">
                    <tr><th>Field</th><th>Type</th><th>Description</th><th>Required</th></tr>
                    <tr>
                        <td><code>transaction_id</code></td>
                        <td>string (UUID)</td>
                        <td>
                            <strong>Middleware transaction ID</strong> — the <code>transaction_id</code> field from <code>GET /api/v1/transactions</code> or the <code>invoice.paid</code> webhook payload.
                            <br><span style="color:#dc2626;">Not the gateway's own transaction reference (<code>gateway_txn_id</code>).</span>
                        </td>
                        <td><span class="badge-req">Required</span></td>
                    </tr>
                    <tr><td><code>amount_cents</code></td><td>integer</td><td>Amount to refund in smallest currency unit. Omit for a full refund. Must not exceed unrefunded balance.</td><td><span class="badge-opt">Optional</span></td></tr>
                    <tr><td><code>reason</code></td><td>string</td><td>Reason for the refund. Stored for audit trail.</td><td><span class="badge-opt">Optional</span></td></tr>
                </table>
                <div class="info-box">
                    <strong>How to get <code>transaction_id</code>:</strong>
                    Call <code>GET /api/v1/transactions</code> and use the <code>transaction_id</code> field from the matching row —
                    or read it directly from the <code>invoice.paid</code> webhook payload (<code>data.transaction_id</code>).
                    The <code>gateway_txn_id</code> field in those responses is the gateway's own reference and is <strong>not</strong> accepted here.
                </div>

                <p class="code-label label-req">Example Request</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>POST {{ $baseUrl }}/api/v1/refunds
Authorization: Bearer {{ $client->pms_client_id }}
Content-Type: application/json

{
  "transaction_id": "uuid-of-captured-txn",
  "amount_cents": 50000,
  "reason": "Customer requested partial refund"
}</pre>
                </div>

                <p class="code-label label-ok">201 — Refund Issued</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "refund_id": "uuid",
  "original_transaction_id": "uuid",
  "invoice_id": "uuid",
  "invoice_number": "INV-2026-001",
  "status": "REFUNDED",
  "amount_cents": 50000,
  "currency": "USD",
  "gateway": "paya",
  "gateway_txn_id": "paya-refund-ref-abc123",
  "created_at": "2026-05-18T10:30:00+00:00"
}</pre>
                </div>

                <p class="code-label label-err">400 — Refund Failed</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "error": { "code": "refund_failed", "message": "This transaction has already been fully refunded." }
}</pre>
                </div>

                <p class="code-label label-err">400 — Amount Exceeds Balance</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "error": { "code": "refund_failed", "message": "Refund amount (75000) exceeds refundable balance (50000)." }
}</pre>
                </div>

                <p class="code-label label-err">404 — Not Found</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "error": { "code": "not_found", "message": "Transaction not found." }
}</pre>
                </div>
            </div>
        </div>

        {{-- GET /transactions --}}
        <div class="endpoint-card" id="ep-transactions">
            <div class="ep-header" onclick="toggleEp('ep-transactions')">
                <span class="method-badge method-get">GET</span>
                <span class="ep-url">/api/v1/transactions</span>
                <span class="ep-desc">List transactions for your account</span>
                <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
            </div>
            <div class="ep-body">
                <p class="ep-desc-text">
                    Returns a paginated list of all transactions processed under your account.
                    Filter by status or paginate using query parameters.
                </p>

                <p class="section-label">Query Parameters</p>
                <table class="doc-table">
                    <tr><th>Parameter</th><th>Type</th><th>Description</th><th>Required</th></tr>
                    <tr><td><code>gateway</code></td><td>string</td><td>Filter to one gateway, e.g. <code>fluidpay</code> or <code>paya</code>. Defaults to all allowed gateways.</td><td><span class="badge-opt">Optional</span></td></tr>
                    <tr><td><code>status</code></td><td>string</td><td>Filter by status, e.g. <code>CAPTURED</code>, <code>REFUNDED</code>, <code>FAILED</code>, <code>VOIDED</code>.</td><td><span class="badge-opt">Optional</span></td></tr>
                    <tr><td><code>start_date</code></td><td>date</td><td>Return transactions on or after this date. Format <code>YYYY-MM-DD</code>.</td><td><span class="badge-opt">Optional</span></td></tr>
                    <tr><td><code>end_date</code></td><td>date</td><td>Return transactions on or before this date. Format <code>YYYY-MM-DD</code>.</td><td><span class="badge-opt">Optional</span></td></tr>
                    <tr><td><code>per_page</code></td><td>integer</td><td>Results per page. Min 1, max 100.</td><td><span class="badge-opt">Optional</span> — default <code>20</code></td></tr>
                    <tr><td><code>page</code></td><td>integer</td><td>Page number.</td><td><span class="badge-opt">Optional</span> — default <code>1</code></td></tr>
                </table>

                <p class="code-label label-req">Example Request</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>GET {{ $baseUrl }}/api/v1/transactions?gateway=fluidpay&status=CAPTURED&per_page=10
Authorization: Bearer {{ $client->pms_client_id }}</pre>
                </div>

                <p class="code-label label-ok">200 — Success</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "data": [
    {
      "transaction_id": "uuid",
      "gateway_txn_id": "fp-txn-abc123",
      "invoice_id": "uuid",
      "invoice_number": "INV-2026-001",
      "gateway": "fluidpay",
      "transaction_type": "debit",
      "status": "CAPTURED",
      "amount_cents": 150000,
      "currency": "USD",
      "created_at": "2026-05-18T08:30:00+00:00"
    }
  ],
  "meta": {
    "total": 42,
    "per_page": 10,
    "page": 1
  }
}</pre>
                </div>
                <div class="info-box" style="margin-top:12px;">
                    <strong>Consistent schema across gateways.</strong>
                    All fields are always present for both <code>fluidpay</code> and <code>paya</code>.
                    Use <code>transaction_id</code> when calling the Refund API — it is always populated regardless of gateway.
                    <code>transaction_type</code> is <code>debit</code> for charges and <code>credit</code> for refunds.
                </div>
            </div>
        </div>
    </div>

    {{-- Webhooks --}}
    <div class="doc-section" id="webhooks">
        <h2>Webhooks</h2>
        <p style="font-size:13px;color:#6b7280;margin:0 0 16px;line-height:1.65;">
            Async results POST to your registered callback URL. Verify the <code style="font-family:ui-monospace,monospace;font-size:12px;background:#f3f4f6;padding:2px 6px;border-radius:4px;">Middleware-Signature</code> header with your
            <code style="font-family:ui-monospace,monospace;font-size:12px;background:#f3f4f6;padding:2px 6px;border-radius:4px;">whsec_</code> secret.
            Retries: 0s, 30s, 2m, 10m, 1h, 6h, 24h — fail after 7 attempts.
        </p>
        <table class="doc-table">
            <tr><th>Event</th><th>Fires when</th></tr>
            <tr><td><span class="event-code">invoice.paid</span></td><td>Hosted page completes successfully (card) or ACH submission accepted.</td></tr>
            <tr><td><span class="event-code">invoice.failed</span></td><td>Gateway declines or page expires.</td></tr>
            <tr><td><span class="event-code">invoice.cancelled</span></td><td>Cancelled via API or expired unpaid.</td></tr>
            <tr><td><span class="event-code">ach.settled</span></td><td>ACH debit clears (typically T+2 to T+5).</td></tr>
            <tr><td><span class="event-code">ach.returned</span></td><td>ACH return (NSF, R01–R85). Can arrive up to 60 days later.</td></tr>
            <tr><td><span class="event-code">refund.completed</span></td><td>Refund settles with the gateway.</td></tr>
            <tr><td><span class="event-code">refund.failed</span></td><td>Refund rejected by the gateway.</td></tr>
        </table>

        <p class="section-label" style="margin-top:24px;">Signature Verification</p>
        <p style="font-size:13px;color:#6b7280;margin:0 0 12px;line-height:1.65;">
            Every delivery includes a <code style="font-family:ui-monospace,monospace;font-size:12px;background:#f3f4f6;padding:2px 6px;border-radius:4px;">Middleware-Signature</code> header.
            Parse <code style="font-family:ui-monospace,monospace;font-size:12px;background:#f3f4f6;padding:2px 6px;border-radius:4px;">t</code> (Unix timestamp) and <code style="font-family:ui-monospace,monospace;font-size:12px;background:#f3f4f6;padding:2px 6px;border-radius:4px;">v1</code> (HMAC-SHA256 hex), then recompute the HMAC over <code style="font-family:ui-monospace,monospace;font-size:12px;background:#f3f4f6;padding:2px 6px;border-radius:4px;">&lt;t&gt;.&lt;raw_body&gt;</code> with your Webhook Secret.
        </p>

        <p class="code-label label-req">Example Payload (invoice.paid)</p>
        <div class="code-block">
            <button class="copy-code" onclick="copyCode(this)">Copy</button>
            <pre>{
  "id": "evt_01HX...",
  "event": "invoice.paid",
  "created_at": "2026-05-18T10:00:00+00:00",
  "data": {
    "invoice_id": "uuid",
    "invoice_number": "INV-2026-001",
    "status": "paid",
    "amount_cents": 150000,
    "currency": "USD",
    "customer": { "name": "John Smith", "email": "john@example.com" },
    "metadata": { "matter_id": "M-9091" },
    "transaction_id": "uuid",
    "gateway": "paya",
    "gateway_txn_id": "paya-ref-abc123",
    "fund_type": "OPERATING"
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
    </div>

    {{-- Errors --}}
    <div class="doc-section" id="errors">
        <h2>Errors</h2>
        <div class="info-box">
            All errors return a JSON body with <code>error.code</code>, <code>error.message</code>, and a <code>request_id</code> you can quote in support tickets.
        </div>
        <table class="doc-table">
            <tr><th>Status</th><th>Code</th><th>Meaning</th></tr>
            <tr><td><span class="status-4xx">400</span></td><td><code>validation_error</code></td><td>Request body failed validation.</td></tr>
            <tr><td><span class="status-4xx">400</span></td><td><code>gateway_not_allowed</code></td><td>Gateway not enabled for this client.</td></tr>
            <tr><td><span class="status-4xx">401</span></td><td><code>invalid_api_key</code></td><td>Missing, malformed, or revoked bearer token.</td></tr>
            <tr><td><span class="status-4xx">404</span></td><td><code>not_found</code></td><td>Resource does not exist.</td></tr>
            <tr><td><span class="status-4xx">409</span></td><td><code>duplicate_idempotency</code></td><td>Same idempotency key, different body.</td></tr>
            <tr><td><span class="status-4xx">429</span></td><td><code>rate_limited</code></td><td>Retry-After header indicates wait time.</td></tr>
            <tr><td><span class="status-5xx">502</span></td><td><code>gateway_error</code></td><td>Upstream gateway error. Retry safe.</td></tr>
        </table>
    </div>

    {{-- Sandbox --}}
    <div class="doc-section" id="sandbox">
        <h2>Sandbox</h2>
        <div class="warn-box">
            <strong>Test mode is active.</strong> Use bank account routing <code>490000018</code> / account <code>123456789</code> for a successful ACH.
            Amounts ending in <code>.99</code> trigger an NSF return after 2 minutes for testing <code>ach.returned</code>.
            No live funds are moved in test mode.
        </div>
    </div>

</div>
</section>
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
            btn.textContent = 'Copied!';
            btn.classList.add('copied');
            setTimeout(function () { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 2000);
        });
    }
    function copyCode(btn) {
        navigator.clipboard.writeText(btn.nextElementSibling.textContent.trim()).then(function () {
            btn.textContent = 'Copied!';
            btn.classList.add('copied');
            setTimeout(function () { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 2000);
        });
    }

    function toggleWhUrlEdit(show) {
        document.getElementById('whurl-display').style.display = show ? 'none' : 'flex';
        document.getElementById('whurl-form').style.display    = show ? 'block' : 'none';
        if (show) document.querySelector('#whurl-form input[name=webhook_url]').focus();
    }

    // ── Allowed Gateways ──────────────────────────────────────────────────
    let currentGateways = @json(array_map('strtoupper', $client->allowed_payment_gateways ?? []));

    function renderGwBadges() {
        const list  = document.getElementById('gw-badge-list');
        const wrap  = document.getElementById('add-gw-wrap');

        list.querySelectorAll('.gw-badge').forEach(b => b.remove());
        const emptyEl = document.getElementById('gw-empty');
        if (emptyEl) emptyEl.remove();

        if (currentGateways.length === 0) {
            const empty = document.createElement('span');
            empty.id = 'gw-empty';
            empty.style.cssText = 'font-size:13px;color:#9ca3af;';
            empty.textContent = 'None configured';
            list.insertBefore(empty, wrap);
        } else {
            [...currentGateways].reverse().forEach(gw => {
                const badge = document.createElement('span');
                badge.className = 'gw-badge';
                badge.dataset.gw = gw;
                badge.innerHTML = gw + ' <button type="button" class="gw-remove-btn" onclick="removeGateway(\'' + gw + '\')" title="Remove ' + gw + '">&#x00D7;</button>';
                list.insertBefore(badge, list.firstChild);
            });
        }

        document.querySelectorAll('.gw-dropdown-item').forEach(item => {
            const added = currentGateways.includes(item.dataset.gw);
            item.classList.toggle('gw-item-added', added);
            let check = item.querySelector('.gw-dropdown-check');
            if (added && !check) {
                check = document.createElement('span');
                check.className = 'gw-dropdown-check';
                check.innerHTML = '&#x2713;';
                item.appendChild(check);
            } else if (!added && check) {
                check.remove();
            }
        });
    }

    function addGateway(gw) {
        if (currentGateways.includes(gw)) return;
        currentGateways.push(gw);
        submitGateways();
    }

    function removeGateway(gw) {
        if (currentGateways.length <= 1) {
            alert('At least one payment gateway must remain. Add another gateway before removing this one.');
            return;
        }
        if (!confirm('Remove ' + gw + ' from allowed gateways?')) return;
        currentGateways = currentGateways.filter(g => g !== gw);
        submitGateways();
    }

    function submitGateways() {
        const form      = document.getElementById('gw-update-form');
        const container = document.getElementById('gw-hidden-inputs');
        container.innerHTML = '';
        currentGateways.forEach(gw => {
            const input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = 'allowed_payment_gateways[]';
            input.value = gw;
            container.appendChild(input);
        });
        document.getElementById('gw-dropdown').classList.remove('open');
        form.submit();
    }

    document.getElementById('add-gw-toggle').addEventListener('click', function (e) {
        e.stopPropagation();
        document.getElementById('gw-dropdown').classList.toggle('open');
    });

    document.querySelectorAll('.gw-dropdown-item').forEach(item => {
        item.addEventListener('click', function () {
            if (!this.classList.contains('gw-item-added')) {
                addGateway(this.dataset.gw);
            }
        });
    });

    document.addEventListener('click', function (e) {
        const wrap = document.getElementById('add-gw-wrap');
        if (wrap && !wrap.contains(e.target)) {
            document.getElementById('gw-dropdown').classList.remove('open');
        }
    });

    // Highlight active nav on scroll
    const sections = ['auth','flow','endpoints','webhooks','errors','sandbox'];
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
    </section>
    </div>{{-- end apidocs tab --}}

</x-inbound::client-config-layout>
</x-inbound::layouts.master>
