<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BookSync — Integration Guide</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=space-grotesk:400,500,600,700&display=swap" rel="stylesheet">
    <style>
        *{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:"Space Grotesk",system-ui,sans-serif;background:#fff;color:#111827;font-size:15px;line-height:1.7;}

        /* ── Layout ── */
        .layout{display:flex;min-height:100vh;}
        .sidebar{width:260px;flex-shrink:0;background:#f9fafb;border-right:1px solid #e5e7eb;padding:32px 0;position:sticky;top:0;height:100vh;overflow-y:auto;}
        .content{flex:1;max-width:820px;padding:48px 56px;min-width:0;}
        @media(max-width:768px){.sidebar{display:none;}.content{padding:32px 24px;}}

        /* ── Sidebar ── */
        .sidebar-brand{padding:0 24px 24px;border-bottom:1px solid #e5e7eb;margin-bottom:16px;}
        .sidebar-brand strong{display:block;font-size:17px;font-weight:700;color:#111827;}
        .sidebar-brand span{font-size:12px;color:#9ca3af;}
        .nav-section{padding:8px 24px 4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#9ca3af;margin-top:8px;}
        .sidebar a{display:block;padding:7px 24px;font-size:13px;color:#6b7280;text-decoration:none;border-left:2px solid transparent;transition:all .12s;}
        .sidebar a:hover{color:#111827;background:#f3f4f6;}
        .sidebar a.active{color:#2563eb;border-left-color:#2563eb;background:#eff6ff;font-weight:500;}

        /* ── Content ── */
        h1{font-size:2rem;font-weight:700;color:#111827;margin-bottom:8px;line-height:1.25;}
        h2{font-size:1.35rem;font-weight:700;color:#111827;margin:48px 0 12px;padding-top:48px;border-top:1px solid #f3f4f6;}
        h2:first-of-type{border-top:none;padding-top:0;}
        h3{font-size:1rem;font-weight:700;color:#111827;margin:24px 0 8px;}
        p{color:#374151;margin-bottom:14px;}
        ul,ol{padding-left:20px;color:#374151;margin-bottom:14px;}
        li{margin-bottom:6px;}
        a{color:#2563eb;}
        code{font-family:ui-monospace,monospace;font-size:.82em;background:#f3f4f6;border:1px solid #e5e7eb;padding:1px 6px;border-radius:4px;color:#374151;}
        strong{font-weight:600;}
        .lead{font-size:1.05rem;color:#6b7280;margin-bottom:28px;}
        hr{border:none;border-top:1px solid #f3f4f6;margin:40px 0;}

        /* ── Badges ── */
        .badge-get{display:inline-block;padding:2px 10px;border-radius:6px;font-size:11px;font-weight:700;background:#dcfce7;color:#166534;font-family:ui-monospace,monospace;}
        .badge-post{display:inline-block;padding:2px 10px;border-radius:6px;font-size:11px;font-weight:700;background:#fef3c7;color:#92400e;font-family:ui-monospace,monospace;}

        /* ── Endpoint block ── */
        .endpoint{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:32px;overflow:hidden;}
        .endpoint-head{padding:14px 20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid #e5e7eb;}
        .endpoint-path{font-family:ui-monospace,monospace;font-size:14px;font-weight:600;color:#111827;}
        .endpoint-desc{font-size:13px;color:#6b7280;margin-left:4px;}
        .endpoint-body{padding:20px;}

        /* ── Code blocks ── */
        .code-wrap{margin-bottom:16px;}
        .code-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:6px;}
        .code-label.green{color:#10b981;}
        .code-label.red{color:#ef4444;}
        .code-block{position:relative;background:#1e293b;border-radius:8px;padding:18px 20px;overflow-x:auto;}
        .code-block pre{font-family:ui-monospace,monospace;font-size:12.5px;color:#e2e8f0;line-height:1.7;white-space:pre;}
        .copy-btn{position:absolute;top:10px;right:10px;padding:3px 10px;background:#334155;color:#94a3b8;border:none;border-radius:5px;font-size:11px;cursor:pointer;font-family:inherit;transition:all .15s;}
        .copy-btn:hover{background:#475569;color:#fff;}
        .copy-btn.ok{background:#10b981;color:#fff;}

        /* ── Tables ── */
        table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;}
        th{text-align:left;padding:9px 14px;background:#f9fafb;color:#6b7280;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e5e7eb;}
        td{padding:10px 14px;border-bottom:1px solid #f3f4f6;vertical-align:top;}
        tr:last-child td{border-bottom:none;}
        td code{font-size:12px;}
        .req{display:inline-block;padding:1px 7px;border-radius:999px;font-size:11px;font-weight:600;background:#fee2e2;color:#991b1b;}
        .opt{display:inline-block;padding:1px 7px;border-radius:999px;font-size:11px;font-weight:600;background:#f3f4f6;color:#6b7280;}

        /* ── Info / note boxes ── */
        .note{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 18px;font-size:13px;color:#1e40af;margin-bottom:20px;line-height:1.65;}
        .warn{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px 18px;font-size:13px;color:#92400e;margin-bottom:20px;line-height:1.65;}

        /* ── Flow steps ── */
        .steps{list-style:none;padding:0;margin:0 0 20px;}
        .steps li{display:flex;gap:14px;padding:12px 0;border-bottom:1px solid #f3f4f6;font-size:14px;color:#374151;align-items:flex-start;}
        .steps li:last-child{border-bottom:none;}
        .step-n{flex-shrink:0;width:26px;height:26px;border-radius:50%;background:#2563eb;color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;margin-top:1px;}

        /* ── Journal entry ── */
        .journal{border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:20px;}
        .journal-head{background:#f9fafb;padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;display:grid;grid-template-columns:1fr auto auto;gap:16px;}
        .journal-row{padding:12px 16px;display:grid;grid-template-columns:1fr auto auto;gap:16px;font-size:13px;border-top:1px solid #f3f4f6;align-items:center;}
        .journal-row.debit .amount-d{font-weight:700;color:#111827;}
        .journal-row.credit .account{padding-left:20px;color:#6b7280;}
        .journal-row.credit .amount-c{font-weight:700;color:#111827;}
        .amount-d,.amount-c{min-width:70px;text-align:right;font-family:ui-monospace,monospace;}

        /* ── Status pills ── */
        .pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:600;}
        .pill-green{background:#dcfce7;color:#166534;}
        .pill-yellow{background:#fef9c3;color:#854d0e;}
        .pill-red{background:#fee2e2;color:#991b1b;}
        .pill-gray{background:#f3f4f6;color:#6b7280;}
    </style>
</head>
<body>
<div class="layout">

{{-- ── Sidebar ── --}}
<nav class="sidebar">
    <div class="sidebar-brand">
        <strong>BookSync</strong>
        <span>Integration Guide</span>
    </div>
    <div class="nav-section">Overview</div>
    <a href="#overview">What is BookSync?</a>
    <a href="#how-it-works">How It Works</a>
    <a href="#auth">Authentication</a>
    <div class="nav-section">Getting Started</div>
    <a href="#quickstart">Quick Start</a>
    <a href="#merchant-setup">Merchant Setup</a>
    <div class="nav-section">API Reference</div>
    <a href="#create-merchant">Create Merchant</a>
    <a href="#get-merchant">Get Merchant Status</a>
    <a href="#list-merchants">List Merchants</a>
    <a href="#post-batch">Post Transactions</a>
    <a href="#get-batch">Get Batch Status</a>
    <div class="nav-section">Concepts</div>
    <a href="#qb-entry">QuickBooks Entry</a>
    <a href="#deduplication">Deduplication</a>
    <a href="#retries">Retry Logic</a>
    <a href="#errors">Error Reference</a>
</nav>

{{-- ── Content ── --}}
<main class="content">

    {{-- OVERVIEW --}}
    <div id="overview">
        <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#9ca3af;margin-bottom:8px;">Integration Guide · v1</p>
        <h1>BookSync</h1>
        <p class="lead">A QuickBooks Online posting service for POS and CRM systems. Send completed transactions to BookSync and they appear as Sales Receipts in your merchants' QuickBooks companies — automatically.</p>

        <h3>Three Parties</h3>
        <table>
            <thead><tr><th>Party</th><th>Role</th></tr></thead>
            <tbody>
                <tr><td><strong>Your Application</strong></td><td>POS or CRM that generates transaction data. Calls the BookSync API.</td></tr>
                <tr><td><strong>BookSync</strong></td><td>Middleware. Receives transactions, posts to QuickBooks on behalf of your merchants.</td></tr>
                <tr><td><strong>QuickBooks Online</strong></td><td>Accounting destination. Each merchant connects their own QBO company.</td></tr>
            </tbody>
        </table>
    </div>

    {{-- HOW IT WORKS --}}
    <h2 id="how-it-works">How It Works</h2>
    <ol class="steps">
        <li><div class="step-n">1</div><div>You <strong>create a merchant</strong> via API. BookSync returns a <code>setup_link</code> and a <code>merchant_id</code>.</div></li>
        <li><div class="step-n">2</div><div>You <strong>send the setup link</strong> to your merchant. They visit it, sign in to QuickBooks Online, and select their deposit account, income item, and default customer.</div></li>
        <li><div class="step-n">3</div><div>You <strong>poll the merchant status</strong> until <code>status === "active"</code>. The response now includes a <code>posting_url</code>.</div></li>
        <li><div class="step-n">4</div><div>You <strong>POST transaction batches</strong> to the <code>posting_url</code>. BookSync creates a QuickBooks Sales Receipt for each transaction.</div></li>
        <li><div class="step-n">5</div><div>Optionally <strong>check batch status</strong> to confirm posting or surface any failures.</div></li>
    </ol>

    {{-- AUTH --}}
    <h2 id="auth">Authentication</h2>
    <p>All API requests require your <strong>Client API Key</strong> as a Bearer token.</p>
    <div class="code-wrap">
        <div class="code-label">Required header on every request</div>
        <div class="code-block">
            <button class="copy-btn" onclick="cp(this)">Copy</button>
            <pre>Authorization: Bearer bsk_your_api_key_here
Content-Type: application/json</pre>
        </div>
    </div>
    <div class="warn">Keep your API key secret. It grants access to all merchants under your account. Contact support immediately if you believe it has been compromised.</div>

    {{-- QUICK START --}}
    <h2 id="quickstart">Quick Start</h2>
    <div class="code-wrap">
        <div class="code-label">1 — Create a merchant</div>
        <div class="code-block">
            <button class="copy-btn" onclick="cp(this)">Copy</button>
            <pre>curl -X POST {{ $baseUrl }}/booksync/api/v1/merchants \
  -H "Authorization: Bearer bsk_your_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "merchant_name": "Downtown Auto Parts",
    "merchant_email": "owner@downtownauto.com"
  }'</pre>
        </div>
    </div>
    <div class="code-wrap">
        <div class="code-label">2 — Poll until active</div>
        <div class="code-block">
            <button class="copy-btn" onclick="cp(this)">Copy</button>
            <pre>curl {{ $baseUrl }}/booksync/api/v1/merchants/m_abc123 \
  -H "Authorization: Bearer bsk_your_api_key"</pre>
        </div>
    </div>
    <div class="code-wrap">
        <div class="code-label">3 — Post transactions</div>
        <div class="code-block">
            <button class="copy-btn" onclick="cp(this)">Copy</button>
            <pre>curl -X POST {{ $baseUrl }}/booksync/post/tok_a1b2c3 \
  -H "Authorization: Bearer bsk_your_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "batch_date": "2026-06-15",
    "transactions": [
      {
        "reference": "POS-TXN-90001",
        "customer_name": "John Smith",
        "amount": 47.50,
        "payment_method": "Credit Card",
        "date": "2026-06-15",
        "memo": "Oil change + filter"
      }
    ]
  }'</pre>
        </div>
    </div>

    {{-- MERCHANT SETUP --}}
    <h2 id="merchant-setup">Merchant Setup</h2>
    <p>After you create a merchant, send them the <code>setup_link</code>. The merchant:</p>
    <ol>
        <li>Visits the link in their browser</li>
        <li>Clicks <strong>Connect QuickBooks</strong> and signs in to their QBO account</li>
        <li>Selects three defaults:
            <ul style="margin-top:6px;">
                <li><strong>Deposit Account</strong> — which bank account sales money goes to (debit)</li>
                <li><strong>Default Item</strong> — the QB service item whose income account receives the credit</li>
                <li><strong>Default Customer</strong> — QB requires a customer on every Sales Receipt (e.g. "Walk-in Customer")</li>
            </ul>
        </li>
        <li>Clicks Save — their <code>status</code> becomes <code>active</code> and the <code>posting_url</code> is generated</li>
    </ol>
    <div class="note">The merchant can update their account settings at any time by revisiting the setup link.</div>

    <hr>

    {{-- API REFERENCE --}}
    <h2 id="create-merchant" style="border-top:none;padding-top:0;">Create Merchant</h2>

    <div class="endpoint">
        <div class="endpoint-head">
            <span class="badge-post">POST</span>
            <span class="endpoint-path">/booksync/api/v1/merchants</span>
            <span class="endpoint-desc">Create a new merchant under your account</span>
        </div>
        <div class="endpoint-body">
            <table>
                <thead><tr><th>Field</th><th>Type</th><th></th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><code>merchant_name</code></td><td>string</td><td><span class="req">Required</span></td><td>Display name (max 255)</td></tr>
                    <tr><td><code>merchant_email</code></td><td>string</td><td><span class="opt">Optional</span></td><td>Merchant email address</td></tr>
                    <tr><td><code>external_merchant_id</code></td><td>string</td><td><span class="opt">Optional</span></td><td>Your own internal ID for this merchant (max 100)</td></tr>
                </tbody>
            </table>
            <div class="code-wrap">
                <div class="code-label">Request</div>
                <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
                    <pre>{
  "merchant_name": "Downtown Auto Parts",
  "merchant_email": "owner@downtownauto.com",
  "external_merchant_id": "POS-MERCH-4421"
}</pre>
                </div>
            </div>
            <div class="code-wrap">
                <div class="code-label green">201 Created</div>
                <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
                    <pre>{
  "merchant_id": "m_8f3a2b1c-9d4e-4f5a-b6c7-d8e9f0a1b2c3",
  "merchant_name": "Downtown Auto Parts",
  "external_merchant_id": "POS-MERCH-4421",
  "status": "pending_qb_connect",
  "qb_connected": false,
  "qb_company_name": null,
  "deposit_account": null,
  "setup_link": "{{ $baseUrl }}/booksync/setup/AbCdEf...",
  "posting_url": null,
  "created_at": "2026-06-15T10:00:00Z",
  "qb_connected_at": null
}</pre>
                </div>
            </div>
            <div class="note">Store the <code>merchant_id</code> and send the <code>setup_link</code> to the merchant. The <code>posting_url</code> is <code>null</code> until setup is complete.</div>
        </div>
    </div>

    {{-- GET MERCHANT --}}
    <h2 id="get-merchant">Get Merchant Status</h2>
    <div class="endpoint">
        <div class="endpoint-head">
            <span class="badge-get">GET</span>
            <span class="endpoint-path">/booksync/api/v1/merchants/{merchant_id}</span>
            <span class="endpoint-desc">Fetch current status for a merchant</span>
        </div>
        <div class="endpoint-body">
            <div class="code-wrap">
                <div class="code-label green">200 OK — merchant active</div>
                <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
                    <pre>{
  "merchant_id": "m_8f3a2b1c-9d4e-4f5a-b6c7-d8e9f0a1b2c3",
  "merchant_name": "Downtown Auto Parts",
  "external_merchant_id": "POS-MERCH-4421",
  "status": "active",
  "qb_connected": true,
  "qb_company_name": "Downtown Auto Parts LLC",
  "deposit_account": "Business Checking",
  "setup_link": "{{ $baseUrl }}/booksync/setup/AbCdEf...",
  "posting_url": "{{ $baseUrl }}/booksync/post/tok_a1b2c3d4e5f6",
  "created_at": "2026-06-15T10:00:00Z",
  "qb_connected_at": "2026-06-15T14:30:00Z"
}</pre>
                </div>
            </div>
            <h3>Merchant Status Values</h3>
            <table>
                <thead><tr><th>Status</th><th>Meaning</th><th>Action</th></tr></thead>
                <tbody>
                    <tr><td><span class="pill pill-yellow">pending_qb_connect</span></td><td>Merchant has not yet connected QuickBooks</td><td>Wait — merchant must visit setup link</td></tr>
                    <tr><td><span class="pill pill-green">active</span></td><td>QB connected, posting URL is ready</td><td>Begin posting transactions</td></tr>
                    <tr><td><span class="pill pill-red">qb_token_expired</span></td><td>QB refresh token expired (100-day inactivity)</td><td>Re-send setup link; merchant must reconnect</td></tr>
                    <tr><td><span class="pill pill-gray">disabled</span></td><td>Merchant disabled by admin</td><td>Contact BookSync support</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- LIST MERCHANTS --}}
    <h2 id="list-merchants">List Merchants</h2>
    <div class="endpoint">
        <div class="endpoint-head">
            <span class="badge-get">GET</span>
            <span class="endpoint-path">/booksync/api/v1/merchants</span>
            <span class="endpoint-desc">List all merchants under your account</span>
        </div>
        <div class="endpoint-body">
            <table>
                <thead><tr><th>Query Param</th><th>Type</th><th></th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><code>status</code></td><td>string</td><td><span class="opt">Optional</span></td><td>Filter by status: <code>pending_qb_connect</code>, <code>active</code>, <code>qb_token_expired</code>, <code>disabled</code></td></tr>
                </tbody>
            </table>
            <div class="code-wrap">
                <div class="code-label green">200 OK</div>
                <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
                    <pre>{
  "data": [ { ...merchant object... } ],
  "total": 1
}</pre>
                </div>
            </div>
        </div>
    </div>

    {{-- POST BATCH --}}
    <h2 id="post-batch">Post Transactions</h2>
    <div class="endpoint">
        <div class="endpoint-head">
            <span class="badge-post">POST</span>
            <span class="endpoint-path">/booksync/post/{merchant_token}</span>
            <span class="endpoint-desc">Submit a batch of transactions for a merchant</span>
        </div>
        <div class="endpoint-body">
            <p>The <code>merchant_token</code> is the last path segment of the merchant's <code>posting_url</code>. Use the full <code>posting_url</code> from the merchant status response.</p>
            <table>
                <thead><tr><th>Field</th><th>Type</th><th></th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><code>batch_date</code></td><td>date</td><td><span class="opt">Optional</span></td><td>Default date for all transactions (YYYY-MM-DD). Defaults to today.</td></tr>
                    <tr><td><code>transactions</code></td><td>array</td><td><span class="req">Required</span></td><td>1–500 transaction objects</td></tr>
                    <tr><td><code>transactions[].reference</code></td><td>string</td><td><span class="req">Required</span></td><td>Unique POS receipt or transaction ID (max 100). Used for deduplication.</td></tr>
                    <tr><td><code>transactions[].customer_name</code></td><td>string</td><td><span class="req">Required</span></td><td>Customer display name. Appears in Sales Receipt description.</td></tr>
                    <tr><td><code>transactions[].amount</code></td><td>decimal</td><td><span class="req">Required</span></td><td>Sale total (e.g. <code>47.50</code>). Must be positive.</td></tr>
                    <tr><td><code>transactions[].payment_method</code></td><td>string</td><td><span class="opt">Optional</span></td><td><code>Cash</code>, <code>Credit Card</code>, <code>Debit Card</code>, <code>Check</code>, <code>Other</code>. Default: <code>Other</code>.</td></tr>
                    <tr><td><code>transactions[].date</code></td><td>date</td><td><span class="opt">Optional</span></td><td>Transaction date (YYYY-MM-DD). Defaults to <code>batch_date</code>.</td></tr>
                    <tr><td><code>transactions[].memo</code></td><td>string</td><td><span class="opt">Optional</span></td><td>Internal note (max 500). Stored in QB <code>PrivateNote</code>.</td></tr>
                    <tr><td><code>transactions[].customer_email</code></td><td>string</td><td><span class="opt">Optional</span></td><td>Not used in QB posting; stored for reference only.</td></tr>
                </tbody>
            </table>
            <div class="code-wrap">
                <div class="code-label">Request</div>
                <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
                    <pre>{
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
    }
  ]
}</pre>
                </div>
            </div>
            <div class="code-wrap">
                <div class="code-label green">200 OK</div>
                <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
                    <pre>{
  "batch_id": "batch_20260615_8f3a2b_xk9mpr",
  "batch_date": "2026-06-15",
  "merchant_id": "m_8f3a2b1c-9d4e-4f5a-b6c7-d8e9f0a1b2c3",
  "total_transactions": 2,
  "posted": 2,
  "skipped": 0,
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
      "qb_customer_id": "42",
      "amount": 125.00
    }
  ]
}</pre>
                </div>
            </div>
            <h3>Per-Transaction Status Values</h3>
            <table>
                <thead><tr><th>Status</th><th>Meaning</th></tr></thead>
                <tbody>
                    <tr><td><span class="pill pill-green">posted</span></td><td>Sales Receipt created in QuickBooks</td></tr>
                    <tr><td><span class="pill pill-gray">already_posted</span></td><td>Duplicate reference — skipped safely, no double posting</td></tr>
                    <tr><td><span class="pill pill-yellow">queued</span></td><td>Accepted, will post shortly</td></tr>
                    <tr><td><span class="pill pill-red">failed</span></td><td>Failed — BookSync will retry automatically</td></tr>
                    <tr><td><span class="pill pill-red">permanently_failed</span></td><td>All 7 retry attempts exhausted — manual action required</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- GET BATCH --}}
    <h2 id="get-batch">Get Batch Status</h2>
    <div class="endpoint">
        <div class="endpoint-head">
            <span class="badge-get">GET</span>
            <span class="endpoint-path">/booksync/api/v1/batches/{batch_id}</span>
            <span class="endpoint-desc">Fetch updated status for a previously submitted batch</span>
        </div>
        <div class="endpoint-body">
            <p>Returns the same structure as the POST response with refreshed per-transaction statuses. Use this after receiving <code>queued</code> or <code>failed</code> results to check final outcomes.</p>
            <div class="code-wrap">
                <div class="code-label">Request</div>
                <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
                    <pre>GET {{ $baseUrl }}/booksync/api/v1/batches/batch_20260615_8f3a2b_xk9mpr
Authorization: Bearer bsk_your_api_key</pre>
                </div>
            </div>
        </div>
    </div>

    <hr>

    {{-- QB ENTRY --}}
    <h2 id="qb-entry">QuickBooks Journal Entry</h2>
    <p>Each posted transaction creates one <strong>Sales Receipt</strong> in the merchant's QuickBooks company. The double-entry behind it:</p>
    <div class="journal">
        <div class="journal-head"><span>Account</span><span>Debit</span><span>Credit</span></div>
        <div class="journal-row debit"><span>Deposit Account &nbsp;<span style="font-size:11px;color:#9ca3af;">(e.g. Business Checking)</span></span><span class="amount-d">$47.50</span><span class="amount-c"></span></div>
        <div class="journal-row credit"><span class="account">Income Account &nbsp;<span style="font-size:11px;color:#9ca3af;">(tied to Default Item)</span></span><span class="amount-d"></span><span class="amount-c">$47.50</span></div>
    </div>
    <h3>What appears on the Sales Receipt</h3>
    <table>
        <thead><tr><th>QB Field</th><th>Value</th><th>Source</th></tr></thead>
        <tbody>
            <tr><td>Customer</td><td>Walk-in Customer (or merchant's chosen default)</td><td>Merchant's Default Customer setting</td></tr>
            <tr><td>Deposit To</td><td>Business Checking (or merchant's chosen account)</td><td>Merchant's Deposit Account setting</td></tr>
            <tr><td>Payment Method</td><td>Credit Card / Cash / etc.</td><td><code>payment_method</code> field in transaction</td></tr>
            <tr><td>Date</td><td>Transaction date</td><td><code>date</code> field in transaction</td></tr>
            <tr><td>Doc Number</td><td>POS-TXN-90001</td><td><code>reference</code> field (max 21 chars)</td></tr>
            <tr><td>Line Description</td><td>John Smith — POS-TXN-90001</td><td><code>customer_name</code> + <code>reference</code></td></tr>
            <tr><td>Line Item</td><td>Services (or merchant's chosen item)</td><td>Merchant's Default Item setting</td></tr>
            <tr><td>Amount</td><td>$47.50</td><td><code>amount</code> field</td></tr>
            <tr><td>Private Note</td><td>Oil change + filter | Posted via BookSync | Batch: …</td><td><code>memo</code> + BookSync metadata</td></tr>
        </tbody>
    </table>

    {{-- DEDUPLICATION --}}
    <h2 id="deduplication">Deduplication</h2>
    <p>BookSync uses the <code>reference</code> field as a unique key per merchant. Re-sending the same reference in a later batch returns <code>already_posted</code> and is completely safe — no duplicate Sales Receipt is created.</p>
    <p>If a transaction previously <code>failed</code> or <code>permanently_failed</code>, re-submitting it in a new batch <strong>resets it and re-queues it</strong> — this is the intended retry mechanism.</p>

    {{-- RETRIES --}}
    <h2 id="retries">Retry Logic</h2>
    <p>Failed transactions are retried automatically on this schedule:</p>
    <table>
        <thead><tr><th>Attempt</th><th>Delay after previous failure</th></tr></thead>
        <tbody>
            <tr><td>2nd</td><td>30 seconds</td></tr>
            <tr><td>3rd</td><td>2 minutes</td></tr>
            <tr><td>4th</td><td>10 minutes</td></tr>
            <tr><td>5th</td><td>1 hour</td></tr>
            <tr><td>6th</td><td>6 hours</td></tr>
            <tr><td>7th</td><td>24 hours</td></tr>
        </tbody>
    </table>
    <p>After 7 failed attempts the transaction becomes <span class="pill pill-red">permanently_failed</span>. To retry, re-submit the reference in a new batch.</p>

    {{-- ERRORS --}}
    <h2 id="errors">Error Reference</h2>
    <table>
        <thead><tr><th>Code</th><th>Meaning</th><th>Action</th></tr></thead>
        <tbody>
            <tr><td><code>400</code></td><td>Invalid request — missing or malformed fields</td><td>Fix the payload and resend</td></tr>
            <tr><td><code>401</code></td><td>Missing or invalid API key</td><td>Check your <code>Authorization</code> header</td></tr>
            <tr><td><code>403</code></td><td>Merchant not active or QB not connected</td><td>Check <code>status</code> field in the error response</td></tr>
            <tr><td><code>404</code></td><td>Merchant token or batch ID not found</td><td>Verify the posting URL or batch ID</td></tr>
            <tr><td><code>409</code></td><td>All transactions in the batch were already posted</td><td>No action needed — safe duplicate</td></tr>
            <tr><td><code>422</code></td><td>Validation error (negative amount, bad date, etc.)</td><td>Fix the specific fields identified in the response</td></tr>
            <tr><td><code>500</code></td><td>BookSync internal error</td><td>Transactions retried automatically</td></tr>
            <tr><td><code>502/503</code></td><td>QuickBooks API unavailable</td><td>Transactions retried automatically</td></tr>
        </tbody>
    </table>

    <div class="code-wrap">
        <div class="code-label red">Example 403 response</div>
        <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
            <pre>{
  "error": "Merchant is not active.",
  "status": "qb_token_expired",
  "detail": "The merchant's QuickBooks token has expired. The merchant must reconnect."
}</pre>
        </div>
    </div>
    <div class="code-wrap">
        <div class="code-label red">Example 422 response</div>
        <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
            <pre>{
  "message": "The given data was invalid.",
  "errors": {
    "transactions.0.amount": ["The amount field must be at least 0.01."],
    "transactions.2.reference": ["The reference field is required."]
  }
}</pre>
        </div>
    </div>

    <hr>
    <p style="font-size:13px;color:#9ca3af;text-align:center;padding-bottom:48px;">BookSync Integration Guide · Questions? Contact your account manager.</p>

</main>
</div>

<script>
    // Copy code blocks
    function cp(btn) {
        const pre = btn.nextElementSibling;
        navigator.clipboard.writeText(pre.textContent.trim()).then(() => {
            btn.textContent = 'Copied!';
            btn.classList.add('ok');
            setTimeout(() => { btn.textContent = 'Copy'; btn.classList.remove('ok'); }, 2000);
        });
    }

    // Sidebar active link on scroll
    const sections = document.querySelectorAll('[id]');
    const links = document.querySelectorAll('.sidebar a');
    const obs = new IntersectionObserver(entries => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                links.forEach(l => l.classList.remove('active'));
                const a = document.querySelector(`.sidebar a[href="#${e.target.id}"]`);
                if (a) a.classList.add('active');
            }
        });
    }, { rootMargin: '-20% 0px -75% 0px' });
    sections.forEach(s => obs.observe(s));
</script>
</body>
</html>
