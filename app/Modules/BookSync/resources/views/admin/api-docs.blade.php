<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BookSync API Docs — {{ $client->name }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=space-grotesk:400,500,600,700&display=swap" rel="stylesheet" />
    <style>
        *{box-sizing:border-box;}
        body{margin:0;font-family:"Space Grotesk",system-ui,sans-serif;background:#f7f8fa;color:#111827;font-size:14px;}

        /* ── Top bar ── */
        .topbar{background:#fff;border-bottom:1px solid #e5e7eb;padding:0 32px;display:flex;align-items:center;gap:0;position:sticky;top:0;z-index:50;}
        .topbar-brand{display:flex;align-items:center;gap:10px;padding:14px 0;border-right:1px solid #e5e7eb;padding-right:20px;margin-right:4px;}
        .topbar-brand strong{font-size:15px;font-weight:700;color:#111827;}
        .topbar-brand span{font-size:12px;color:#6b7280;background:#f3f4f6;padding:2px 8px;border-radius:6px;}
        .tab-nav{display:flex;gap:0;}
        .tab-nav a{display:inline-block;padding:14px 18px;font-size:13px;font-weight:500;color:#6b7280;text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;transition:color .15s,border-color .15s;}
        .tab-nav a:hover{color:#111827;}
        .tab-nav a.active{color:#2563eb;border-bottom-color:#2563eb;}
        .topbar-back{margin-left:auto;font-size:12px;color:#6b7280;text-decoration:none;padding:6px 14px;border:1px solid #e5e7eb;border-radius:8px;transition:all .15s;}
        .topbar-back:hover{background:#f3f4f6;color:#111827;}

        /* ── Tab panels ── */
        .tab-panel{display:none;padding:32px;}
        .tab-panel.active{display:block;}

        /* ── Credentials panel ── */
        .cred-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:24px;}
        @media(max-width:640px){.cred-grid{grid-template-columns:1fr;}}
        .cred-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px 20px;}
        .cred-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9ca3af;margin-bottom:8px;}
        .cred-row{display:flex;align-items:center;gap:8px;}
        .cred-row code{flex:1;font-family:ui-monospace,monospace;font-size:12px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:6px;padding:7px 10px;color:#374151;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;}
        .copy-btn{flex-shrink:0;padding:5px 12px;background:#2563eb;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;transition:background .15s;font-family:inherit;}
        .copy-btn:hover{background:#1d4ed8;}
        .copy-btn.copied{background:#10b981;}
        .cred-hint{font-size:12px;color:#9ca3af;margin-top:6px;}

        /* ── Info / warn boxes ── */
        .info-box{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 18px;font-size:13px;color:#1e40af;margin-bottom:20px;line-height:1.65;}
        .warn-box{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px 18px;font-size:13px;color:#92400e;margin-bottom:20px;line-height:1.65;}
        .success-box{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 18px;font-size:13px;color:#166534;margin-bottom:20px;line-height:1.65;}

        /* ── Section headings ── */
        .section-title{font-size:18px;font-weight:700;color:#111827;margin:0 0 16px;padding-bottom:10px;border-bottom:1px solid #e5e7eb;}
        .section-sub{font-size:13px;color:#6b7280;margin:-10px 0 20px;line-height:1.65;}

        /* ── Flow steps ── */
        .flow{list-style:none;padding:0;margin:0 0 24px;}
        .flow li{display:flex;gap:14px;padding:11px 0;border-bottom:1px solid #f3f4f6;font-size:13px;color:#374151;line-height:1.6;align-items:flex-start;}
        .flow li:last-child{border-bottom:none;}
        .step-dot{flex-shrink:0;width:24px;height:24px;border-radius:50%;background:#2563eb;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;margin-top:1px;}
        .flow li code{font-family:ui-monospace,monospace;font-size:12px;background:#f3f4f6;padding:1px 6px;border-radius:4px;}

        /* ── Endpoint cards ── */
        .ep-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;margin-bottom:10px;}
        .ep-head{display:flex;align-items:center;gap:12px;padding:14px 20px;cursor:pointer;user-select:none;}
        .ep-head:hover{background:#f9fafb;}
        .method{display:inline-flex;align-items:center;justify-content:center;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:700;letter-spacing:.04em;flex-shrink:0;}
        .m-post{background:#fef3c7;color:#92400e;}
        .m-get{background:#dcfce7;color:#166534;}
        .ep-path{font-family:ui-monospace,monospace;font-size:13px;color:#111827;font-weight:500;}
        .ep-summary{font-size:13px;color:#6b7280;margin-left:4px;}
        .chevron{margin-left:auto;flex-shrink:0;color:#9ca3af;transition:transform .2s;}
        .ep-card.open .chevron{transform:rotate(180deg);}
        .ep-body{display:none;border-top:1px solid #f3f4f6;padding:24px;}
        .ep-card.open .ep-body{display:block;}
        .ep-desc{font-size:13px;color:#6b7280;margin:0 0 20px;line-height:1.65;}

        /* ── Param tables ── */
        .param-table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;}
        .param-table th{text-align:left;padding:8px 14px;background:#f9fafb;color:#6b7280;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e5e7eb;}
        .param-table td{padding:10px 14px;border-bottom:1px solid #f3f4f6;vertical-align:top;}
        .param-table tr:last-child td{border-bottom:none;}
        .param-table td code{font-family:ui-monospace,monospace;font-size:12px;background:#f3f4f6;padding:2px 6px;border-radius:4px;}
        .req{display:inline-block;padding:2px 7px;border-radius:999px;font-size:11px;font-weight:600;background:#fee2e2;color:#991b1b;}
        .opt{display:inline-block;padding:2px 7px;border-radius:999px;font-size:11px;font-weight:600;background:#f3f4f6;color:#6b7280;}

        /* ── Code blocks ── */
        .code-wrap{position:relative;margin-bottom:16px;}
        .code-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin:0 0 6px;}
        .label-req{color:#6b7280;}
        .label-ok{color:#10b981;}
        .label-err{color:#ef4444;}
        .code-block{background:#1e293b;border-radius:8px;padding:16px;overflow-x:auto;}
        .code-block pre{margin:0;font-family:ui-monospace,monospace;font-size:12px;color:#e2e8f0;line-height:1.65;white-space:pre;}
        .copy-code{position:absolute;top:28px;right:10px;padding:3px 10px;background:#334155;color:#94a3b8;border:none;border-radius:5px;font-size:11px;cursor:pointer;transition:all .15s;font-family:inherit;}
        .copy-code:hover{background:#475569;color:#fff;}
        .copy-code.copied{background:#10b981;color:#fff;}

        /* ── Error table ── */
        .error-table{width:100%;border-collapse:collapse;font-size:13px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;}
        .error-table th{text-align:left;padding:8px 14px;background:#f9fafb;color:#6b7280;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e5e7eb;}
        .error-table td{padding:10px 14px;border-bottom:1px solid #f3f4f6;vertical-align:top;}
        .error-table tr:last-child td{border-bottom:none;}
        .sc{display:inline-block;padding:2px 8px;border-radius:6px;font-size:12px;font-weight:700;}
        .sc-2xx{background:#dcfce7;color:#166534;}
        .sc-4xx{background:#fee2e2;color:#991b1b;}
        .sc-5xx{background:#fef3c7;color:#92400e;}

        /* ── Status badge ── */
        .status-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:600;}
        .s-pending{background:#fef9e7;color:#9a7d0a;}
        .s-active{background:#d5f5e3;color:#1e8449;}
        .s-expired{background:#fdf2f8;color:#76448a;}
        .s-disabled{background:#f2d7d5;color:#922b21;}
    </style>
</head>
<body>

{{-- ── Top bar ── --}}
<div class="topbar">
    <div class="topbar-brand">
        <strong>BookSync</strong>
        <span>API Docs</span>
    </div>
    <nav class="tab-nav">
        <a href="#" class="active" data-tab="credentials">Credentials</a>
        <a href="#" data-tab="quickstart">Quick Start</a>
        <a href="#" data-tab="merchants">Merchants API</a>
        <a href="#" data-tab="posting">Posting API</a>
        <a href="#" data-tab="errors">Errors</a>
    </nav>
    <a href="{{ route('booksync.admin.clients.show', $client->client_id) }}" class="topbar-back">← Back to Client</a>
</div>

{{-- ══════════════════════════════════════════════════════════════ --}}
{{-- TAB: Credentials --}}
{{-- ══════════════════════════════════════════════════════════════ --}}
<div id="tab-credentials" class="tab-panel active">

    <div class="section-title">API Credentials — {{ $client->name }}</div>
    <p class="section-sub">Use these values to authenticate all API requests from your application to BookSync.</p>

    <div class="cred-grid">
        <div class="cred-card">
            <div class="cred-label">Client API Key <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#b0b8c4;">— Bearer token</span></div>
            <div class="cred-row">
                <code id="api-key-val">{{ session('api_key_plaintext') ?? '•••••••••••••••••••••••••••••••• (not shown after creation)' }}</code>
                @if(session('api_key_plaintext'))
                <button class="copy-btn" onclick="copyVal('api-key-val', this)">Copy</button>
                @endif
            </div>
            <div class="cred-hint">Send as <code style="font-family:ui-monospace,monospace;font-size:11px;background:#f3f4f6;padding:1px 5px;border-radius:4px;">Authorization: Bearer &lt;key&gt;</code> on every request.</div>
        </div>
        <div class="cred-card">
            <div class="cred-label">Client ID</div>
            <div class="cred-row">
                <code id="client-id-val">{{ $client->client_id }}</code>
                <button class="copy-btn" onclick="copyVal('client-id-val', this)">Copy</button>
            </div>
            <div class="cred-hint">Reference this ID when contacting BookSync support.</div>
        </div>
        <div class="cred-card">
            <div class="cred-label">Base URL</div>
            <div class="cred-row">
                <code id="base-url-val">{{ $baseUrl }}</code>
                <button class="copy-btn" onclick="copyVal('base-url-val', this)">Copy</button>
            </div>
            <div class="cred-hint">Prefix all API paths with this URL.</div>
        </div>
        <div class="cred-card">
            <div class="cred-label">Status</div>
            <div style="margin-top:4px;">
                <span class="status-pill {{ $client->status === 'active' ? 's-active' : 's-disabled' }}">
                    {{ ucfirst($client->status) }}
                </span>
            </div>
            <div class="cred-hint">All API calls fail with 401 when the client is inactive.</div>
        </div>
    </div>

    <div class="warn-box">
        <strong>Keep your API key secret.</strong> It grants full access to all merchants under your account.
        If you believe it has been compromised, contact BookSync support immediately to rotate it.
    </div>

    <div class="section-title" style="margin-top:32px;">Authentication Header</div>
    <div class="code-wrap">
        <div class="code-label label-req">Every request must include:</div>
        <div class="code-block">
            <pre>Authorization: Bearer {{ $client->client_id }}
Content-Type: application/json</pre>
        </div>
    </div>

</div>

{{-- ══════════════════════════════════════════════════════════════ --}}
{{-- TAB: Quick Start --}}
{{-- ══════════════════════════════════════════════════════════════ --}}
<div id="tab-quickstart" class="tab-panel">

    <div class="section-title">Quick Start</div>
    <p class="section-sub">Three steps to start syncing transactions to QuickBooks.</p>

    <ul class="flow">
        <li>
            <div class="step-dot">1</div>
            <div>
                <strong>Create a merchant</strong> — call <code>POST /booksync/api/v1/merchants</code> with the merchant's name.
                BookSync returns a <code>merchant_id</code> and a <code>setup_link</code>.
            </div>
        </li>
        <li>
            <div class="step-dot">2</div>
            <div>
                <strong>Send the setup link to the merchant</strong> — the merchant visits the link, signs in to QuickBooks Online,
                and selects which bank account to deposit into. No action required from you.
            </div>
        </li>
        <li>
            <div class="step-dot">3</div>
            <div>
                <strong>Poll merchant status</strong> — call <code>GET /booksync/api/v1/merchants/{merchant_id}</code> until
                <code>status</code> is <code>active</code>. The response then includes the merchant's <code>posting_url</code>.
            </div>
        </li>
        <li>
            <div class="step-dot">4</div>
            <div>
                <strong>Post transactions</strong> — send a batch <code>POST</code> to the merchant's <code>posting_url</code>.
                BookSync creates a QuickBooks Sales Receipt for each transaction.
            </div>
        </li>
        <li>
            <div class="step-dot">5</div>
            <div>
                <strong>Check batch status</strong> — optionally call <code>GET /booksync/api/v1/batches/{batch_id}</code> to
                confirm all transactions posted, or to see which failed and will be retried.
            </div>
        </li>
    </ul>

    <div class="info-box">
        <strong>Deduplication:</strong> BookSync uses the <code>reference</code> field as a unique key per merchant.
        Re-sending the same reference returns <code>already_posted</code> — it is safe to retry batches without risk of double-posting.
    </div>

</div>

{{-- ══════════════════════════════════════════════════════════════ --}}
{{-- TAB: Merchants API --}}
{{-- ══════════════════════════════════════════════════════════════ --}}
<div id="tab-merchants" class="tab-panel">

    <div class="section-title">Merchants API</div>
    <p class="section-sub">Create and manage merchants. Each merchant maps to one QuickBooks Online company.</p>

    {{-- ── POST /merchants ── --}}
    <div class="ep-card open">
        <div class="ep-head" onclick="toggleEp(this)">
            <span class="method m-post">POST</span>
            <span class="ep-path">/booksync/api/v1/merchants</span>
            <span class="ep-summary">Create a merchant</span>
            <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
        </div>
        <div class="ep-body">
            <p class="ep-desc">Creates a new merchant under your client account. Returns a <code>setup_link</code> to send to the merchant so they can connect their QuickBooks account.</p>

            <div class="code-label label-req">Request headers</div>
            <div class="code-wrap">
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>POST {{ $baseUrl }}/booksync/api/v1/merchants
Authorization: Bearer {your_api_key}
Content-Type: application/json</pre>
                </div>
            </div>

            <p class="code-label label-req" style="margin-top:16px;">Request body</p>
            <table class="param-table">
                <thead><tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><code>merchant_name</code></td><td>string</td><td><span class="req">Required</span></td><td>Display name for the merchant (max 255 chars)</td></tr>
                    <tr><td><code>merchant_email</code></td><td>string</td><td><span class="opt">Optional</span></td><td>Merchant's email — for notifications</td></tr>
                    <tr><td><code>external_merchant_id</code></td><td>string</td><td><span class="opt">Optional</span></td><td>Your own ID for this merchant (max 100 chars) — stored as reference</td></tr>
                </tbody>
            </table>

            <div class="code-wrap">
                <div class="code-label label-req">Example request</div>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "merchant_name": "Downtown Auto Parts",
  "merchant_email": "owner@downtownauto.com",
  "external_merchant_id": "POS-MERCH-4421"
}</pre>
                </div>
            </div>

            <div class="code-wrap">
                <div class="code-label label-ok">201 Created</div>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "merchant_id": "m_8f3a2b1c-9d4e-4f5a-b6c7-d8e9f0a1b2c3",
  "merchant_name": "Downtown Auto Parts",
  "external_merchant_id": "POS-MERCH-4421",
  "status": "pending_qb_connect",
  "qb_connected": false,
  "qb_company_name": null,
  "deposit_account": null,
  "setup_link": "{{ $baseUrl }}/booksync/setup/AbCdEfGhIjKlMnOpQrStUvWxYzAbCdEfGhIjKlMnOpQrSt",
  "posting_url": null,
  "created_at": "2026-06-15T10:00:00Z",
  "qb_connected_at": null
}</pre>
                </div>
            </div>

            <div class="info-box" style="margin-bottom:0;">
                <strong>Next step:</strong> Send the <code>setup_link</code> to the merchant. The <code>posting_url</code> is <code>null</code> until the merchant completes QuickBooks setup.
            </div>
        </div>
    </div>

    {{-- ── GET /merchants ── --}}
    <div class="ep-card">
        <div class="ep-head" onclick="toggleEp(this)">
            <span class="method m-get">GET</span>
            <span class="ep-path">/booksync/api/v1/merchants</span>
            <span class="ep-summary">List all merchants</span>
            <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
        </div>
        <div class="ep-body">
            <p class="ep-desc">Returns all merchants under your account. Use the optional <code>status</code> query parameter to filter.</p>

            <table class="param-table">
                <thead><tr><th>Query param</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><code>status</code></td><td>string</td><td><span class="opt">Optional</span></td><td>Filter by status: <code>pending_qb_connect</code>, <code>active</code>, <code>qb_token_expired</code>, <code>disabled</code></td></tr>
                </tbody>
            </table>

            <div class="code-wrap">
                <div class="code-label label-req">Example</div>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>GET {{ $baseUrl }}/booksync/api/v1/merchants?status=active
Authorization: Bearer {your_api_key}</pre>
                </div>
            </div>

            <div class="code-wrap">
                <div class="code-label label-ok">200 OK</div>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "data": [
    {
      "merchant_id": "m_8f3a2b1c-9d4e-4f5a-b6c7-d8e9f0a1b2c3",
      "merchant_name": "Downtown Auto Parts",
      "external_merchant_id": "POS-MERCH-4421",
      "status": "active",
      "qb_connected": true,
      "qb_company_name": "Downtown Auto Parts LLC",
      "deposit_account": "Business Checking - 4421",
      "setup_link": "{{ $baseUrl }}/booksync/setup/AbCdEf...",
      "posting_url": "{{ $baseUrl }}/booksync/post/tok_a1b2c3d4e5f6",
      "created_at": "2026-06-15T10:00:00Z",
      "qb_connected_at": "2026-06-15T14:30:00Z"
    }
  ],
  "total": 1
}</pre>
                </div>
            </div>
        </div>
    </div>

    {{-- ── GET /merchants/{id} ── --}}
    <div class="ep-card">
        <div class="ep-head" onclick="toggleEp(this)">
            <span class="method m-get">GET</span>
            <span class="ep-path">/booksync/api/v1/merchants/{merchant_id}</span>
            <span class="ep-summary">Get merchant status</span>
            <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
        </div>
        <div class="ep-body">
            <p class="ep-desc">Returns full details for a single merchant. Poll this endpoint after sending the setup link until <code>status === "active"</code>, at which point <code>posting_url</code> is populated and ready to receive transactions.</p>

            <div class="code-wrap">
                <div class="code-label label-req">Example</div>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>GET {{ $baseUrl }}/booksync/api/v1/merchants/m_8f3a2b1c-9d4e-4f5a-b6c7-d8e9f0a1b2c3
Authorization: Bearer {your_api_key}</pre>
                </div>
            </div>

            <div class="code-wrap">
                <div class="code-label label-ok">200 OK — merchant active</div>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "merchant_id": "m_8f3a2b1c-9d4e-4f5a-b6c7-d8e9f0a1b2c3",
  "merchant_name": "Downtown Auto Parts",
  "external_merchant_id": "POS-MERCH-4421",
  "status": "active",
  "qb_connected": true,
  "qb_company_name": "Downtown Auto Parts LLC",
  "deposit_account": "Business Checking - 4421",
  "setup_link": "{{ $baseUrl }}/booksync/setup/AbCdEf...",
  "posting_url": "{{ $baseUrl }}/booksync/post/tok_a1b2c3d4e5f6",
  "created_at": "2026-06-15T10:00:00Z",
  "qb_connected_at": "2026-06-15T14:30:00Z"
}</pre>
                </div>
            </div>

            <p class="code-label label-req" style="margin-top:4px;">Status values</p>
            <table class="param-table">
                <thead><tr><th>Value</th><th>Meaning</th><th>Action</th></tr></thead>
                <tbody>
                    <tr><td><span class="status-pill s-pending">pending_qb_connect</span></td><td>Merchant has not yet connected QuickBooks</td><td>Wait — merchant must visit the setup link</td></tr>
                    <tr><td><span class="status-pill s-active">active</span></td><td>QuickBooks connected, posting URL is ready</td><td>Begin sending transactions</td></tr>
                    <tr><td><span class="status-pill s-expired">qb_token_expired</span></td><td>QB refresh token expired (100-day inactivity)</td><td>Re-send the setup link; merchant must reconnect</td></tr>
                    <tr><td><span class="status-pill s-disabled">disabled</span></td><td>Merchant disabled by admin</td><td>Contact BookSync support</td></tr>
                </tbody>
            </table>
        </div>
    </div>

</div>

{{-- ══════════════════════════════════════════════════════════════ --}}
{{-- TAB: Posting API --}}
{{-- ══════════════════════════════════════════════════════════════ --}}
<div id="tab-posting" class="tab-panel">

    <div class="section-title">Posting API</div>
    <p class="section-sub">Post daily transaction batches to QuickBooks. Each batch creates one Sales Receipt per transaction in the merchant's QuickBooks company.</p>

    {{-- ── POST /booksync/post/{token} ── --}}
    <div class="ep-card open">
        <div class="ep-head" onclick="toggleEp(this)">
            <span class="method m-post">POST</span>
            <span class="ep-path">/booksync/post/{merchant_token}</span>
            <span class="ep-summary">Post a transaction batch</span>
            <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
        </div>
        <div class="ep-body">
            <p class="ep-desc">
                The primary endpoint. Send completed POS transactions here — BookSync creates a QuickBooks Sales Receipt for each one.
                The <code>merchant_token</code> is the last segment of the merchant's <code>posting_url</code>.
                Use the same <code>Authorization</code> header as all other endpoints.
            </p>

            <div class="warn-box">
                <strong>merchant_token vs merchant_id:</strong> The posting URL uses a separate <code>posting_token</code> (e.g. <code>tok_a1b2...</code>), not the <code>merchant_id</code>.
                Use the full <code>posting_url</code> from the merchant status response.
            </div>

            <p class="code-label label-req">Request body fields</p>
            <table class="param-table">
                <thead><tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><code>batch_date</code></td><td>date</td><td><span class="opt">Optional</span></td><td>Default date for transactions (YYYY-MM-DD). Defaults to today if omitted.</td></tr>
                    <tr><td><code>transactions</code></td><td>array</td><td><span class="req">Required</span></td><td>1–500 transaction objects</td></tr>
                    <tr><td><code>transactions[].reference</code></td><td>string</td><td><span class="req">Required</span></td><td>Unique POS receipt/transaction ID (max 100). Used for deduplication — safe to re-send.</td></tr>
                    <tr><td><code>transactions[].customer_name</code></td><td>string</td><td><span class="req">Required</span></td><td>Customer display name. BookSync finds or creates this QB customer.</td></tr>
                    <tr><td><code>transactions[].amount</code></td><td>decimal</td><td><span class="req">Required</span></td><td>Total sale amount (e.g. <code>47.50</code>). Must be positive.</td></tr>
                    <tr><td><code>transactions[].payment_method</code></td><td>string</td><td><span class="opt">Optional</span></td><td>One of: <code>Cash</code>, <code>Credit Card</code>, <code>Debit Card</code>, <code>Check</code>, <code>Other</code>. Defaults to <code>Other</code>.</td></tr>
                    <tr><td><code>transactions[].date</code></td><td>date</td><td><span class="opt">Optional</span></td><td>Transaction date (YYYY-MM-DD). Defaults to <code>batch_date</code>.</td></tr>
                    <tr><td><code>transactions[].memo</code></td><td>string</td><td><span class="opt">Optional</span></td><td>Internal note (max 500 chars). Stored in QB Sales Receipt <code>PrivateNote</code>.</td></tr>
                    <tr><td><code>transactions[].customer_email</code></td><td>string</td><td><span class="opt">Optional</span></td><td>Used when creating a new QB customer (not required for matching).</td></tr>
                </tbody>
            </table>

            <div class="code-wrap">
                <div class="code-label label-req">Example request</div>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>POST {{ $baseUrl }}/booksync/post/tok_a1b2c3d4e5f6
Authorization: Bearer {your_api_key}
Content-Type: application/json

{
  "batch_date": "2026-06-15",
  "transactions": [
    {
      "reference": "POS-TXN-90001",
      "customer_name": "John Smith",
      "amount": 47.50,
      "payment_method": "Credit Card",
      "date": "2026-06-15",
      "memo": "Oil change + filter"
    },
    {
      "reference": "POS-TXN-90002",
      "customer_name": "Sarah Johnson",
      "amount": 125.00,
      "payment_method": "Debit Card"
    },
    {
      "reference": "POS-TXN-90003",
      "customer_name": "Walk-in Customer",
      "amount": 18.99,
      "payment_method": "Cash"
    }
  ]
}</pre>
                </div>
            </div>

            <div class="code-wrap">
                <div class="code-label label-ok">200 OK</div>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "batch_id": "batch_20260615_m8f3a",
  "batch_date": "2026-06-15",
  "merchant_id": "m_8f3a2b1c-9d4e-4f5a-b6c7-d8e9f0a1b2c3",
  "total_transactions": 3,
  "posted": 2,
  "skipped": 1,
  "failed": 0,
  "queued": 0,
  "results": [
    {
      "reference": "POS-TXN-90001",
      "status": "posted",
      "qb_salesreceipt_id": "178",
      "qb_customer_id": "42",
      "amount": 47.50
    },
    {
      "reference": "POS-TXN-90002",
      "status": "posted",
      "qb_salesreceipt_id": "179",
      "qb_customer_id": "65",
      "amount": 125.00
    },
    {
      "reference": "POS-TXN-90003",
      "status": "already_posted",
      "qb_salesreceipt_id": "170",
      "amount": 18.99,
      "message": "Transaction with this reference was posted on 2026-06-12T08:00:00Z"
    }
  ]
}</pre>
                </div>
            </div>

            <p class="code-label label-req" style="margin-top:4px;">Result status values per transaction</p>
            <table class="param-table">
                <thead><tr><th>Value</th><th>Meaning</th></tr></thead>
                <tbody>
                    <tr><td><code>posted</code></td><td>Sales Receipt created in QuickBooks</td></tr>
                    <tr><td><code>already_posted</code></td><td>Duplicate reference — skipped safely, no double-posting</td></tr>
                    <tr><td><code>failed</code></td><td>Posting failed; BookSync will retry automatically (up to 7 attempts)</td></tr>
                    <tr><td><code>queued</code></td><td>Accepted and will be posted shortly (large batches only)</td></tr>
                    <tr><td><code>permanently_failed</code></td><td>All 7 retry attempts exhausted — manual intervention required</td></tr>
                </tbody>
            </table>

            <div class="info-box" style="margin-bottom:0;">
                <strong>Retry schedule:</strong> Failed transactions are retried at 30s → 2m → 10m → 1h → 6h → 24h.
                The <code>batch_id</code> can be used to check final status via the batch status endpoint.
            </div>
        </div>
    </div>

    {{-- ── GET /batches/{id} ── --}}
    <div class="ep-card">
        <div class="ep-head" onclick="toggleEp(this)">
            <span class="method m-get">GET</span>
            <span class="ep-path">/booksync/api/v1/batches/{batch_id}</span>
            <span class="ep-summary">Get batch status</span>
            <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
        </div>
        <div class="ep-body">
            <p class="ep-desc">Returns the current status of a previously submitted batch, with updated per-transaction statuses. Use this to confirm all transactions have posted or to surface any that permanently failed.</p>

            <div class="code-wrap">
                <div class="code-label label-req">Example</div>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>GET {{ $baseUrl }}/booksync/api/v1/batches/batch_20260615_m8f3a
Authorization: Bearer {your_api_key}</pre>
                </div>
            </div>

            <div class="code-wrap">
                <div class="code-label label-ok">200 OK — same structure as POST response with updated statuses</div>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "batch_id": "batch_20260615_m8f3a",
  "batch_date": "2026-06-15",
  "merchant_id": "m_8f3a2b1c-9d4e-4f5a-b6c7-d8e9f0a1b2c3",
  "total_transactions": 3,
  "posted": 3,
  "skipped": 0,
  "failed": 0,
  "queued": 0,
  "results": [ ... ]
}</pre>
                </div>
            </div>
        </div>
    </div>

</div>

{{-- ══════════════════════════════════════════════════════════════ --}}
{{-- TAB: Errors --}}
{{-- ══════════════════════════════════════════════════════════════ --}}
<div id="tab-errors" class="tab-panel">

    <div class="section-title">Error Reference</div>
    <p class="section-sub">BookSync returns standard HTTP status codes. All error responses include an <code>error</code> field with a human-readable message.</p>

    <table class="error-table">
        <thead>
            <tr><th>Code</th><th>Meaning</th><th>Action</th></tr>
        </thead>
        <tbody>
            <tr>
                <td><span class="sc sc-2xx">200</span></td>
                <td>Batch accepted and processed</td>
                <td>Check per-transaction <code>status</code> fields in the response</td>
            </tr>
            <tr>
                <td><span class="sc sc-2xx">201</span></td>
                <td>Merchant created</td>
                <td>Store <code>merchant_id</code> and send <code>setup_link</code> to merchant</td>
            </tr>
            <tr>
                <td><span class="sc sc-4xx">400</span></td>
                <td>Invalid request — missing required fields or invalid format</td>
                <td>Fix the payload and resend</td>
            </tr>
            <tr>
                <td><span class="sc sc-4xx">401</span></td>
                <td>Invalid or missing <code>Authorization</code> header</td>
                <td>Check your API key</td>
            </tr>
            <tr>
                <td><span class="sc sc-4xx">403</span></td>
                <td>Merchant is not active or QuickBooks is not connected</td>
                <td>Check <code>status</code> field in response — merchant may need to (re)connect QuickBooks</td>
            </tr>
            <tr>
                <td><span class="sc sc-4xx">404</span></td>
                <td>Merchant token or batch ID not found</td>
                <td>Verify the posting URL or batch ID</td>
            </tr>
            <tr>
                <td><span class="sc sc-4xx">409</span></td>
                <td>All transactions in the batch were already posted</td>
                <td>No action needed — deduplication worked correctly</td>
            </tr>
            <tr>
                <td><span class="sc sc-4xx">422</span></td>
                <td>Validation error (e.g. negative amount, invalid date format)</td>
                <td>Fix the specific field(s) identified in the response</td>
            </tr>
            <tr>
                <td><span class="sc sc-5xx">500</span></td>
                <td>BookSync internal error</td>
                <td>Failed transactions are retried automatically</td>
            </tr>
            <tr>
                <td><span class="sc sc-5xx">502 / 503</span></td>
                <td>QuickBooks API unavailable</td>
                <td>Failed transactions are retried automatically</td>
            </tr>
        </tbody>
    </table>

    <div class="section-title" style="margin-top:32px;">Error Response Format</div>
    <div class="code-wrap">
        <div class="code-label label-err">Example 403 response</div>
        <div class="code-block">
            <button class="copy-code" onclick="copyCode(this)">Copy</button>
            <pre>{
  "error": "Merchant is not active.",
  "status": "qb_token_expired",
  "detail": "The merchant's QuickBooks token has expired. The merchant must reconnect."
}</pre>
        </div>
    </div>

    <div class="code-wrap">
        <div class="code-label label-err">Example 422 response</div>
        <div class="code-block">
            <button class="copy-code" onclick="copyCode(this)">Copy</button>
            <pre>{
  "message": "The given data was invalid.",
  "errors": {
    "transactions.0.amount": ["The amount field must be at least 0.01."],
    "transactions.2.reference": ["The reference field is required."]
  }
}</pre>
        </div>
    </div>

</div>

<script>
    // ── Tab switching ──────────────────────────────────────────────
    document.querySelectorAll('.tab-nav a').forEach(a => {
        a.addEventListener('click', e => {
            e.preventDefault();
            document.querySelectorAll('.tab-nav a').forEach(x => x.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach(x => x.classList.remove('active'));
            a.classList.add('active');
            document.getElementById('tab-' + a.dataset.tab).classList.add('active');
        });
    });

    // ── Endpoint toggle ────────────────────────────────────────────
    function toggleEp(head) {
        head.parentElement.classList.toggle('open');
    }

    // ── Copy credential value ──────────────────────────────────────
    function copyVal(id, btn) {
        navigator.clipboard.writeText(document.getElementById(id).textContent.trim()).then(() => {
            btn.textContent = 'Copied!';
            btn.classList.add('copied');
            setTimeout(() => { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 2000);
        });
    }

    // ── Copy code block ────────────────────────────────────────────
    function copyCode(btn) {
        const pre = btn.nextElementSibling;
        navigator.clipboard.writeText(pre.textContent.trim()).then(() => {
            btn.textContent = 'Copied!';
            btn.classList.add('copied');
            setTimeout(() => { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 2000);
        });
    }
</script>

</body>
</html>
