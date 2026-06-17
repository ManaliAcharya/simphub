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

        .layout{display:flex;min-height:100vh;}
        .sidebar{width:268px;flex-shrink:0;background:#f9fafb;border-right:1px solid #e5e7eb;padding:32px 0;position:sticky;top:0;height:100vh;overflow-y:auto;}
        .content{flex:1;max-width:840px;padding:48px 56px;min-width:0;}
        @media(max-width:768px){.sidebar{display:none;}.content{padding:32px 24px;}}

        .sidebar-brand{padding:0 24px 24px;border-bottom:1px solid #e5e7eb;margin-bottom:16px;}
        .sidebar-brand strong{display:block;font-size:17px;font-weight:700;color:#111827;}
        .sidebar-brand span{font-size:12px;color:#9ca3af;}
        .nav-section{padding:8px 24px 4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#9ca3af;margin-top:8px;}
        .sidebar a{display:block;padding:6px 24px;font-size:13px;color:#6b7280;text-decoration:none;border-left:2px solid transparent;transition:all .12s;}
        .sidebar a:hover{color:#111827;background:#f3f4f6;}
        .sidebar a.active{color:#2563eb;border-left-color:#2563eb;background:#eff6ff;font-weight:500;}

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

        .badge-get{display:inline-block;padding:2px 10px;border-radius:6px;font-size:11px;font-weight:700;background:#dcfce7;color:#166534;font-family:ui-monospace,monospace;}
        .badge-post{display:inline-block;padding:2px 10px;border-radius:6px;font-size:11px;font-weight:700;background:#fef3c7;color:#92400e;font-family:ui-monospace,monospace;}

        .endpoint{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:32px;overflow:hidden;}
        .endpoint-head{padding:14px 20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid #e5e7eb;flex-wrap:wrap;}
        .endpoint-path{font-family:ui-monospace,monospace;font-size:14px;font-weight:600;color:#111827;}
        .endpoint-desc{font-size:13px;color:#6b7280;}
        .endpoint-body{padding:20px;}

        .code-wrap{margin-bottom:16px;}
        .code-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:6px;}
        .code-label.green{color:#10b981;}
        .code-label.red{color:#ef4444;}
        .code-label.blue{color:#2563eb;}
        .code-block{position:relative;background:#1e293b;border-radius:8px;padding:18px 20px;overflow-x:auto;}
        .code-block pre{font-family:ui-monospace,monospace;font-size:12.5px;color:#e2e8f0;line-height:1.7;white-space:pre;}
        .copy-btn{position:absolute;top:10px;right:10px;padding:3px 10px;background:#334155;color:#94a3b8;border:none;border-radius:5px;font-size:11px;cursor:pointer;font-family:inherit;transition:all .15s;}
        .copy-btn:hover{background:#475569;color:#fff;}
        .copy-btn.ok{background:#10b981;color:#fff;}

        table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;}
        th{text-align:left;padding:9px 14px;background:#f9fafb;color:#6b7280;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e5e7eb;}
        td{padding:10px 14px;border-bottom:1px solid #f3f4f6;vertical-align:top;}
        tr:last-child td{border-bottom:none;}
        td code{font-size:12px;}
        .req{display:inline-block;padding:1px 7px;border-radius:999px;font-size:11px;font-weight:600;background:#fee2e2;color:#991b1b;}
        .opt{display:inline-block;padding:1px 7px;border-radius:999px;font-size:11px;font-weight:600;background:#f3f4f6;color:#6b7280;}

        .note{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 18px;font-size:13px;color:#1e40af;margin-bottom:20px;line-height:1.65;}
        .warn{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px 18px;font-size:13px;color:#92400e;margin-bottom:20px;line-height:1.65;}
        .danger{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 18px;font-size:13px;color:#991b1b;margin-bottom:20px;line-height:1.65;}

        .steps{list-style:none;padding:0;margin:0 0 20px;}
        .steps li{display:flex;gap:14px;padding:12px 0;border-bottom:1px solid #f3f4f6;font-size:14px;color:#374151;align-items:flex-start;}
        .steps li:last-child{border-bottom:none;}
        .step-n{flex-shrink:0;width:26px;height:26px;border-radius:50%;background:#2563eb;color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;margin-top:1px;}

        .journal{border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:20px;}
        .journal-head{background:#f9fafb;padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;display:grid;grid-template-columns:1fr auto auto;gap:16px;}
        .journal-row{padding:12px 16px;display:grid;grid-template-columns:1fr auto auto;gap:16px;font-size:13px;border-top:1px solid #f3f4f6;align-items:center;}
        .journal-row.debit .amount-d{font-weight:700;color:#111827;}
        .journal-row.credit .account{padding-left:20px;color:#6b7280;}
        .journal-row.credit .amount-c{font-weight:700;color:#111827;}
        .amount-d,.amount-c{min-width:70px;text-align:right;font-family:ui-monospace,monospace;}

        .pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:600;}
        .pill-green{background:#dcfce7;color:#166534;}
        .pill-yellow{background:#fef9c3;color:#854d0e;}
        .pill-red{background:#fee2e2;color:#991b1b;}
        .pill-gray{background:#f3f4f6;color:#6b7280;}

        .signing-formula{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:16px 20px;margin-bottom:16px;font-family:ui-monospace,monospace;font-size:13px;color:#166534;line-height:2;}
        .signing-formula span{color:#9ca3af;font-family:"Space Grotesk",sans-serif;font-size:12px;}
    </style>
</head>
<body>
<div class="layout">

<nav class="sidebar">
    <div class="sidebar-brand">
        <strong>BookSync</strong>
        <span>Integration Guide · v2</span>
    </div>
    <div class="nav-section">Overview</div>
    <a href="#overview">What is BookSync?</a>
    <a href="#how-it-works">How It Works</a>
    <div class="nav-section">Authentication</div>
    <a href="#auth-management">Management Endpoints</a>
    <a href="#auth-posting">Posting Endpoint</a>
    <a href="#request-signing">Request Signing</a>
    <a href="#rotate-secret">Rotate Signing Secret</a>
    <div class="nav-section">Getting Started</div>
    <a href="#quickstart">Quick Start</a>
    <a href="#merchant-setup">Merchant Setup</a>
    <a href="#setup-callback">Setup Callback</a>
    <div class="nav-section">API Reference</div>
    <a href="#create-merchant">Create Merchant</a>
    <a href="#get-merchant">Get Merchant Status</a>
    <a href="#list-merchants">List Merchants</a>
    <a href="#post-batch">Post Transactions</a>
    <a href="#get-batch">Get Batch Status</a>
    <div class="nav-section">Concepts</div>
    <a href="#qb-entry">QuickBooks Entry</a>
    <a href="#surcharge">Surcharge</a>
    <a href="#deduplication">Deduplication</a>
    <a href="#retries">Retry Logic</a>
    <a href="#errors">Error Reference</a>
</nav>

<main class="content">

    {{-- OVERVIEW --}}
    <div id="overview">
        <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#9ca3af;margin-bottom:8px;">Integration Guide · v2</p>
        <h1>BookSync</h1>
        <p class="lead">A QuickBooks Online posting service for POS and CRM systems. Send completed transactions to BookSync and they appear as Sales Receipts in your merchants' QuickBooks companies — automatically.</p>

        <h3>Three Parties</h3>
        <table>
            <thead><tr><th>Party</th><th>Role</th></tr></thead>
            <tbody>
                <tr><td><strong>Your Application</strong></td><td>POS or CRM that generates transaction data. Calls the BookSync API.</td></tr>
                <tr><td><strong>BookSync</strong></td><td>Middleware. Receives transactions, verifies request signatures, and posts to QuickBooks on behalf of your merchants.</td></tr>
                <tr><td><strong>QuickBooks Online</strong></td><td>Accounting destination. Each merchant connects their own QBO company independently.</td></tr>
            </tbody>
        </table>
    </div>

    {{-- HOW IT WORKS --}}
    <h2 id="how-it-works">How It Works</h2>
    <ol class="steps">
        <li><div class="step-n">1</div><div>You <strong>create a merchant</strong> via API. BookSync returns a <code>merchant_id</code>, a <code>setup_link</code>, and a one-time <code>signing_secret</code>. Store the signing secret securely — it is never shown again.</div></li>
        <li><div class="step-n">2</div><div>You <strong>send the setup link</strong> to your merchant. They visit it, sign into QuickBooks Online, and configure their deposit account, income item, and default customer.</div></li>
        <li><div class="step-n">3</div><div>BookSync <strong>notifies you</strong> via your optional <code>callback_url</code> the moment setup completes. Alternatively, poll the merchant status endpoint until <code>status === "active"</code>.</div></li>
        <li><div class="step-n">4</div><div>You <strong>POST transaction batches</strong> to the merchant's <code>posting_url</code>, signing each request with HMAC-SHA256. BookSync creates a Sales Receipt in QuickBooks for each transaction.</div></li>
        <li><div class="step-n">5</div><div>Optionally <strong>check batch status</strong> to confirm posting outcomes or surface failures.</div></li>
    </ol>

    {{-- AUTH — MANAGEMENT --}}
    <h2 id="auth-management">Authentication — Management Endpoints</h2>
    <p>All merchant management endpoints (<code>POST /merchants</code>, <code>GET /merchants</code>, etc.) require only your <strong>Client API Key</strong>:</p>
    <div class="code-wrap">
        <div class="code-label">Headers — management endpoints</div>
        <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
            <pre>Authorization: Bearer {client_api_key}
Content-Type: application/json</pre>
        </div>
    </div>
    <div class="warn">Your Client API Key grants access to all merchants under your account. Keep it secret. Rotate it through your account manager if compromised.</div>

    {{-- AUTH — POSTING --}}
    <h2 id="auth-posting">Authentication — Posting Endpoint</h2>
    <p>The transaction posting endpoint requires <strong>three</strong> headers: the Client API Key, an HMAC signature, and a Unix timestamp:</p>
    <div class="code-wrap">
        <div class="code-label">Headers — posting endpoint</div>
        <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
            <pre>Authorization: Bearer {client_api_key}
Content-Type: application/json
X-BookSync-Signature: sha256={hmac_hex}
X-BookSync-Timestamp: {unix_timestamp}</pre>
        </div>
    </div>
    <p>The timestamp and signature together prevent replay attacks. Requests with a timestamp older than <strong>5 minutes</strong> are rejected regardless of signature validity.</p>

    {{-- REQUEST SIGNING --}}
    <h2 id="request-signing">Request Signing</h2>
    <p>Each transaction POST must be signed using the merchant's <code>signing_secret</code> (returned once at merchant creation). The signature covers both the request body and the timestamp, so the signature cannot be reused.</p>

    <h3>Signing formula</h3>
    <div class="signing-formula">
        signed_payload &nbsp;= &nbsp;timestamp + "." + raw_request_body<br>
        signature &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;= &nbsp;"sha256=" + HMAC‑SHA256(signed_payload, signing_secret)
    </div>

    <div class="note">
        <strong>Use the raw JSON body</strong> — do not parse and re-serialize. The body must be byte-for-byte identical when BookSync recomputes the HMAC.
        Whitespace and key ordering must match exactly.
    </div>

    <h3>PHP example</h3>
    <div class="code-wrap">
        <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
            <pre>$signingSecret = 'bss_...';   // merchant signing_secret
$postingUrl    = 'https://paymentmiddleware.myreporthub.dev/booksync/post/tok_...';
$timestamp     = time();      // current Unix timestamp (integer)

$body = json_encode([
    'batch_date'   => '2026-06-17',
    'transactions' => [
        ['reference' => 'POS-TXN-001', 'amount' => 47.50]
    ]
]);

$signedPayload = $timestamp . '.' . $body;
$signature     = 'sha256=' . hash_hmac('sha256', $signedPayload, $signingSecret);

$response = Http::withHeaders([
    'Authorization'        => 'Bearer ' . $clientApiKey,
    'X-BookSync-Timestamp' => (string) $timestamp,
    'X-BookSync-Signature' => $signature,
])->post($postingUrl, json_decode($body, true));</pre>
        </div>
    </div>

    <h3>Node.js example</h3>
    <div class="code-wrap">
        <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
            <pre>const crypto = require('crypto');

const signingSecret = 'bss_...';
const timestamp     = Math.floor(Date.now() / 1000).toString();
const body          = JSON.stringify({ batch_date: '2026-06-17', transactions: [...] });

const signedPayload = `${timestamp}.${body}`;
const signature     = 'sha256=' + crypto
    .createHmac('sha256', signingSecret)
    .update(signedPayload)
    .digest('hex');

await fetch(postingUrl, {
    method:  'POST',
    headers: {
        'Authorization':        `Bearer ${clientApiKey}`,
        'Content-Type':         'application/json',
        'X-BookSync-Timestamp': timestamp,
        'X-BookSync-Signature': signature,
    },
    body,
});</pre>
        </div>
    </div>

    <h3>Signature error responses</h3>
    <table>
        <thead><tr><th>Scenario</th><th>HTTP</th><th>Error</th></tr></thead>
        <tbody>
            <tr><td>Missing <code>X-BookSync-Timestamp</code></td><td>401</td><td><code>Missing X-BookSync-Timestamp header.</code></td></tr>
            <tr><td>Timestamp older than 5 minutes</td><td>401</td><td><code>Request timestamp is too old or too far in the future.</code></td></tr>
            <tr><td>Missing <code>X-BookSync-Signature</code></td><td>401</td><td><code>Missing X-BookSync-Signature header.</code></td></tr>
            <tr><td>Signature does not match</td><td>401</td><td><code>Invalid signature.</code></td></tr>
        </tbody>
    </table>

    {{-- ROTATE SECRET --}}
    <h2 id="rotate-secret">Rotate Signing Secret</h2>
    <p>If a <code>signing_secret</code> is lost or compromised, rotate it without downtime. The previous secret remains valid for <strong>30 minutes</strong> after rotation, giving you time to deploy the new secret.</p>

    <div class="endpoint">
        <div class="endpoint-head">
            <span class="badge-post">POST</span>
            <span class="endpoint-path">/booksync/api/v1/merchants/{merchant_id}/rotate-secret</span>
        </div>
        <div class="endpoint-body">
            <div class="code-wrap">
                <div class="code-label green">200 OK</div>
                <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
                    <pre>{
  "merchant_id": "m_8f3a2b1c",
  "signing_secret": "bss_NEW_secret_value...",
  "previous_secret_valid_until": "2026-06-17T13:30:00Z"
}</pre>
                </div>
            </div>
            <div class="danger">The new secret is shown <strong>only once</strong> in this response. Store it immediately. After <code>previous_secret_valid_until</code>, only the new secret is accepted.</div>
        </div>
    </div>

    {{-- QUICK START --}}
    <h2 id="quickstart">Quick Start</h2>
    <div class="code-wrap">
        <div class="code-label">1 — Create a merchant</div>
        <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
            <pre>curl -X POST {{ $baseUrl }}/booksync/api/v1/merchants \
  -H "Authorization: Bearer {client_api_key}" \
  -H "Content-Type: application/json" \
  -d '{
    "merchant_name": "Downtown Auto Parts",
    "callback_url":  "https://your-pos.com/webhooks/booksync"
  }'
# → 201: save merchant_id, setup_link, signing_secret</pre>
        </div>
    </div>
    <div class="code-wrap">
        <div class="code-label">2 — Wait for active (poll or use callback)</div>
        <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
            <pre>curl {{ $baseUrl }}/booksync/api/v1/merchants/{merchant_id} \
  -H "Authorization: Bearer {client_api_key}"
# → check "status": "active" and save "posting_url"</pre>
        </div>
    </div>
    <div class="code-wrap">
        <div class="code-label">3 — Post a transaction (signed)</div>
        <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
            <pre># compute timestamp and signature first (see Request Signing section)
curl -X POST {posting_url} \
  -H "Authorization: Bearer {client_api_key}" \
  -H "Content-Type: application/json" \
  -H "X-BookSync-Timestamp: {timestamp}" \
  -H "X-BookSync-Signature: sha256={hmac}" \
  -d '{
    "batch_date": "2026-06-17",
    "transactions": [
      { "reference": "POS-001", "amount": 47.50 }
    ]
  }'</pre>
        </div>
    </div>

    {{-- MERCHANT SETUP --}}
    <h2 id="merchant-setup">Merchant Setup</h2>
    <p>Send the <code>setup_link</code> to your merchant. BookSync handles the entire setup UI — you have no front-end work to do. The merchant:</p>
    <ol>
        <li>Visits the link and clicks <strong>Connect to QuickBooks</strong></li>
        <li>Signs into their QBO account and authorizes BookSync</li>
        <li>Selects four settings on the BookSync setup page:
            <ul style="margin-top:6px;">
                <li><strong>Deposit Account</strong> — bank account where money is deposited (debit side)</li>
                <li><strong>Default Item</strong> — QBO service/product item for revenue recognition (credit side)</li>
                <li><strong>Default Customer</strong> — QBO requires a customer on every Sales Receipt; use a generic record such as "Walk-in Customer"</li>
                <li><strong>Surcharge Item</strong> — optional; only required if your POS collects surcharge fees</li>
            </ul>
        </li>
        <li>Saves — their status becomes <code>active</code> and the <code>posting_url</code> is generated</li>
    </ol>
    <div class="note">
        <strong>BookSync never creates QuickBooks records.</strong> All accounts, items, and customers must already exist in the merchant's QBO company. If a required record is missing, the merchant creates it in QBO and clicks Refresh on the setup page.
    </div>
    <p>The merchant can update their selections at any time by revisiting the setup link.</p>

    {{-- SETUP CALLBACK --}}
    <h2 id="setup-callback">Setup Callback</h2>
    <p>If you provide a <code>callback_url</code> when creating the merchant, BookSync POSTs to it the moment the merchant completes setup:</p>
    <div class="code-wrap">
        <div class="code-label blue">POST {callback_url}</div>
        <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
            <pre>{
  "merchant_id": "m_8f3a2b1c",
  "status": "active",
  "posting_url": "{{ $baseUrl }}/booksync/post/tok_..."
}</pre>
        </div>
    </div>
    <p>The callback fires once, with a 5-second timeout. Delivery is best-effort — if your endpoint is unavailable, the event is not retried. Use the <a href="#get-merchant">Get Merchant Status</a> endpoint to reconcile if needed.</p>

    <hr>

    {{-- CREATE MERCHANT --}}
    <h2 id="create-merchant" style="border-top:none;padding-top:0;">Create Merchant</h2>
    <div class="endpoint">
        <div class="endpoint-head">
            <span class="badge-post">POST</span>
            <span class="endpoint-path">/booksync/api/v1/merchants</span>
            <span class="endpoint-desc">Register a new merchant under your account</span>
        </div>
        <div class="endpoint-body">
            <table>
                <thead><tr><th>Field</th><th>Type</th><th></th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><code>merchant_name</code></td><td>string</td><td><span class="req">Required</span></td><td>Display name (max 255)</td></tr>
                    <tr><td><code>merchant_email</code></td><td>string</td><td><span class="opt">Optional</span></td><td>Merchant contact email</td></tr>
                    <tr><td><code>external_merchant_id</code></td><td>string</td><td><span class="opt">Optional</span></td><td>Your internal ID for this merchant (max 100)</td></tr>
                    <tr><td><code>callback_url</code></td><td>string</td><td><span class="opt">Optional</span></td><td>URL BookSync will POST to when merchant completes setup</td></tr>
                </tbody>
            </table>
            <div class="code-wrap">
                <div class="code-label">Request</div>
                <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
                    <pre>{
  "merchant_name":        "Island Dive Shop",
  "merchant_email":       "owner@islanddive.com",
  "external_merchant_id": "POS-MERCH-4421",
  "callback_url":         "https://your-pos.com/webhooks/booksync"
}</pre>
                </div>
            </div>
            <div class="code-wrap">
                <div class="code-label green">201 Created</div>
                <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
                    <pre>{
  "merchant_id":     "m_8f3a2b1c-9d4e-4f5a-b6c7-d8e9f0a1b2c3",
  "merchant_name":   "Island Dive Shop",
  "merchant_token":  "AbCdEf...",
  "status":          "pending_qb_connect",
  "qb_connected":    false,
  "qb_company_name": null,
  "deposit_account": null,
  "default_item":    null,
  "default_customer": null,
  "surcharge_enabled": false,
  "surcharge_item":  null,
  "setup_link":      "{{ $baseUrl }}/booksync/setup/AbCdEf...",
  "posting_url":     null,
  "signing_secret":  "bss_a7c3e9f1d4b8...",
  "created_at":      "2026-06-17T10:00:00Z",
  "qb_connected_at": null
}</pre>
                </div>
            </div>
            <div class="danger">
                <strong>signing_secret is shown only in this response.</strong> Store it immediately in your secrets manager. It cannot be retrieved again — only rotated via the <a href="#rotate-secret">rotate endpoint</a>.
            </div>
        </div>
    </div>

    {{-- GET MERCHANT --}}
    <h2 id="get-merchant">Get Merchant Status</h2>
    <div class="endpoint">
        <div class="endpoint-head">
            <span class="badge-get">GET</span>
            <span class="endpoint-path">/booksync/api/v1/merchants/{merchant_id}</span>
            <span class="endpoint-desc">Fetch current status and configuration for a merchant</span>
        </div>
        <div class="endpoint-body">
            <div class="code-wrap">
                <div class="code-label">200 OK — before QuickBooks connection</div>
                <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
                    <pre>{
  "merchant_id":      "m_8f3a2b1c",
  "merchant_name":    "Island Dive Shop",
  "status":           "pending_qb_connect",
  "qb_connected":     false,
  "qb_company_name":  null,
  "deposit_account":  null,
  "default_item":     null,
  "default_customer": null,
  "surcharge_enabled": false,
  "surcharge_item":   null,
  "posting_url":      null
}</pre>
                </div>
            </div>
            <div class="code-wrap">
                <div class="code-label green">200 OK — after setup complete</div>
                <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
                    <pre>{
  "merchant_id":     "m_8f3a2b1c",
  "merchant_name":   "Island Dive Shop",
  "status":          "active",
  "qb_connected":    true,
  "qb_company_name": "Island Dive Shop Inc.",
  "deposit_account": { "id": "35", "name": "Business Checking" },
  "default_item":    { "id": "8",  "name": "Services" },
  "default_customer":{ "id": "42", "name": "Walk-in Customer" },
  "surcharge_enabled": true,
  "surcharge_item":  { "id": "15", "name": "Surcharge Fee" },
  "posting_url":     "{{ $baseUrl }}/booksync/post/tok_...",
  "created_at":      "2026-06-17T10:00:00Z",
  "qb_connected_at": "2026-06-17T14:30:00Z"
}</pre>
                </div>
            </div>
            <h3>Merchant Status Values</h3>
            <table>
                <thead><tr><th>Status</th><th>Meaning</th><th>Action</th></tr></thead>
                <tbody>
                    <tr><td><span class="pill pill-yellow">pending_qb_connect</span></td><td>Merchant has not yet completed setup</td><td>Send the <code>setup_link</code> to the merchant</td></tr>
                    <tr><td><span class="pill pill-green">active</span></td><td>QB connected, fully configured, posting enabled</td><td>Begin posting transactions</td></tr>
                    <tr><td><span class="pill pill-red">qb_token_expired</span></td><td>QB refresh token expired (100-day inactivity)</td><td>Re-send setup link; merchant must reconnect</td></tr>
                    <tr><td><span class="pill pill-gray">disabled</span></td><td>Manually disabled by admin</td><td>Contact BookSync support</td></tr>
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
                <thead><tr><th>Query Param</th><th></th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><code>status</code></td><td><span class="opt">Optional</span></td><td>Filter: <code>pending_qb_connect</code>, <code>active</code>, <code>qb_token_expired</code>, <code>disabled</code></td></tr>
                </tbody>
            </table>
            <div class="code-wrap">
                <div class="code-label green">200 OK</div>
                <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
                    <pre>{ "data": [ { ...merchant object... } ], "total": 1 }</pre>
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
            <span class="endpoint-desc">Submit a signed batch of transactions</span>
        </div>
        <div class="endpoint-body">
            <p>Use the full <code>posting_url</code> from the merchant status response. This endpoint requires all three authentication headers — see <a href="#auth-posting">Posting Authentication</a>.</p>

            <h3>Transaction fields</h3>
            <table>
                <thead><tr><th>Field</th><th></th><th>Default</th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><code>reference</code></td><td><span class="req">Required</span></td><td>—</td><td>Your unique receipt/transaction ID (max 100). Deduplication key — same reference = skipped as <code>already_posted</code>.</td></tr>
                    <tr><td><code>amount</code></td><td><span class="req">Required</span></td><td>—</td><td>Sale amount before surcharge. Must be &gt; 0.</td></tr>
                    <tr><td><code>customer_name</code></td><td><span class="opt">Optional</span></td><td>Default Customer</td><td>Only used when the merchant has <strong>no Default Customer</strong> configured. If a Default Customer is set, this field is ignored and the Default Customer is always used. When no default exists and <code>customer_name</code> is provided, it must match an existing QBO DisplayName exactly — not found → <code>customer_not_found</code> (not retried).</td></tr>
                    <tr><td><code>surcharge</code></td><td><span class="opt">Optional</span></td><td>0</td><td>Surcharge amount. Posts as a separate line item using the merchant's Surcharge Item. If &gt; 0 and surcharge is not enabled → <code>surcharge_not_enabled</code> (not retried).</td></tr>
                    <tr><td><code>date</code></td><td><span class="opt">Optional</span></td><td><code>batch_date</code></td><td>Transaction date (YYYY-MM-DD).</td></tr>
                    <tr><td><code>payment_method</code></td><td><span class="opt">Optional</span></td><td><code>Other</code></td><td>Must match an existing QBO PaymentMethod: <code>Cash</code>, <code>Credit Card</code>, <code>Debit Card</code>, <code>Check</code>, <code>Other</code>. Not found → <code>payment_method_not_found</code> (not retried).</td></tr>
                    <tr><td><code>memo</code></td><td><span class="opt">Optional</span></td><td>—</td><td>Stored in the Sales Receipt <code>PrivateNote</code> (max 500 chars).</td></tr>
                    <tr><td><code>customer_email</code></td><td><span class="opt">Optional</span></td><td>—</td><td>Stored for reference only; not sent to QBO.</td></tr>
                </tbody>
            </table>

            <h3>Minimum payload</h3>
            <div class="code-wrap">
                <div class="code-label">Only reference and amount required</div>
                <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
                    <pre>{
  "batch_date": "2026-06-17",
  "transactions": [
    { "reference": "DAILY-20260617-CC", "amount": 2340.00 }
  ]
}</pre>
                </div>
            </div>

            <h3>Full payload with surcharge</h3>
            <div class="code-wrap">
                <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
                    <pre>{
  "batch_date": "2026-06-17",
  "transactions": [
    {
      "reference":      "POS-TXN-90001",
      "customer_name":  "John Smith",
      "amount":         47.50,
      "surcharge":      1.66,
      "date":           "2026-06-17",
      "payment_method": "Credit Card",
      "memo":           "Oil change + filter",
      "customer_email": "john@example.com"
    }
  ]
}</pre>
                </div>
            </div>

            <div class="code-wrap">
                <div class="code-label green">200 OK</div>
                <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
                    <pre>{
  "batch_id":           "batch_20260617_8f3a2b_xk9mpr",
  "batch_date":         "2026-06-17",
  "total_transactions": 2,
  "posted":             1,
  "skipped":            1,
  "failed":             0,
  "queued":             0,
  "results": [
    {
      "reference":  "POS-TXN-90001",
      "status":     "posted",
      "qb_txn_id":  "178",
      "doc_number": "POS-TXN-90001",
      "amount":     47.50
    },
    {
      "reference":         "POS-TXN-90001",
      "status":            "already_posted",
      "original_batch_id": "batch_20260616_8f3a2b_abc123",
      "amount":            47.50
    }
  ]
}</pre>
                </div>
            </div>

            <h3>Per-transaction status values</h3>
            <table>
                <thead><tr><th>Status</th><th>Retried?</th><th>Meaning</th></tr></thead>
                <tbody>
                    <tr><td><span class="pill pill-green">posted</span></td><td>—</td><td>Sales Receipt created in QuickBooks</td></tr>
                    <tr><td><span class="pill pill-gray">already_posted</span></td><td>—</td><td>Duplicate reference; skipped safely. <code>original_batch_id</code> identifies the first posting.</td></tr>
                    <tr><td><span class="pill pill-yellow">queued</span></td><td>—</td><td>Accepted, posting in progress</td></tr>
                    <tr><td><span class="pill pill-red">failed</span></td><td>Yes — auto</td><td>Transient QBO error; will retry on schedule</td></tr>
                    <tr><td><span class="pill pill-red">permanently_failed</span></td><td>Manual resubmit</td><td>All 7 retry attempts exhausted. Re-submit the reference in a new batch to retry.</td></tr>
                    <tr><td><span class="pill pill-red">customer_not_found</span></td><td>No</td><td><code>customer_name</code> provided (and no Default Customer is set on the merchant) but no matching QBO customer exists. Create the customer in QBO first, or configure a Default Customer in the merchant QB setup.</td></tr>
                    <tr><td><span class="pill pill-red">payment_method_not_found</span></td><td>No</td><td><code>payment_method</code> provided but not found in QBO. Create it in QBO first.</td></tr>
                    <tr><td><span class="pill pill-red">surcharge_not_enabled</span></td><td>No</td><td>Surcharge amount provided but merchant has surcharge disabled. Enable it in the merchant QB setup.</td></tr>
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
            <p>Returns the same structure as the POST response with refreshed per-transaction statuses. Poll this after receiving <code>queued</code> or <code>failed</code> results.</p>
            <div class="code-wrap">
                <div class="code-label">Request — management headers only (no HMAC required)</div>
                <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
                    <pre>GET {{ $baseUrl }}/booksync/api/v1/batches/batch_20260617_8f3a2b_xk9mpr
Authorization: Bearer {client_api_key}</pre>
                </div>
            </div>
        </div>
    </div>

    <hr>

    {{-- QB ENTRY --}}
    <h2 id="qb-entry">QuickBooks Journal Entry</h2>
    <p>Each posted transaction creates one <strong>Sales Receipt</strong> in the merchant's QuickBooks company.</p>

    <h3>Standard transaction (no surcharge)</h3>
    <div class="journal">
        <div class="journal-head"><span>Account</span><span>Debit</span><span>Credit</span></div>
        <div class="journal-row debit"><span>Deposit Account &nbsp;<span style="font-size:11px;color:#9ca3af;">(e.g. Business Checking)</span></span><span class="amount-d">$47.50</span><span class="amount-c"></span></div>
        <div class="journal-row credit"><span class="account">Income Account &nbsp;<span style="font-size:11px;color:#9ca3af;">(tied to Default Item)</span></span><span class="amount-d"></span><span class="amount-c">$47.50</span></div>
    </div>

    <h3>Transaction with surcharge</h3>
    <div class="journal">
        <div class="journal-head"><span>Account</span><span>Debit</span><span>Credit</span></div>
        <div class="journal-row debit"><span>Deposit Account &nbsp;<span style="font-size:11px;color:#9ca3af;">(receives full combined amount)</span></span><span class="amount-d">$49.16</span><span class="amount-c"></span></div>
        <div class="journal-row credit"><span class="account">Income Account &nbsp;<span style="font-size:11px;color:#9ca3af;">(Default Item → Line 1)</span></span><span class="amount-d"></span><span class="amount-c">$47.50</span></div>
        <div class="journal-row credit"><span class="account">Surcharge Income Account &nbsp;<span style="font-size:11px;color:#9ca3af;">(Surcharge Item → Line 2)</span></span><span class="amount-d"></span><span class="amount-c">$1.66</span></div>
    </div>

    <h3>Sales Receipt field mapping</h3>
    <table>
        <thead><tr><th>QB Field</th><th>Value</th><th>Source</th></tr></thead>
        <tbody>
            <tr><td>Customer</td><td>Walk-in Customer</td><td>Merchant's Default Customer (always used when set; <code>customer_name</code> in payload is ignored). If no default, falls back to QBO lookup by <code>customer_name</code>.</td></tr>
            <tr><td>Deposit To</td><td>Business Checking</td><td>Merchant's Deposit Account setting</td></tr>
            <tr><td>Payment Method</td><td>Credit Card</td><td><code>payment_method</code> — must exist in QBO</td></tr>
            <tr><td>Date</td><td>2026-06-17</td><td><code>date</code> field (or <code>batch_date</code>)</td></tr>
            <tr><td>Doc Number</td><td>POS-TXN-90001</td><td><code>reference</code> (truncated to 21 chars in QBO)</td></tr>
            <tr><td>Line 1 Description</td><td>John Smith — POS-TXN-90001</td><td><code>customer_name</code> + <code>reference</code></td></tr>
            <tr><td>Line 1 Item</td><td>Services</td><td>Merchant's Default Item</td></tr>
            <tr><td>Line 1 Amount</td><td>$47.50</td><td><code>amount</code></td></tr>
            <tr><td>Line 2 Description</td><td>Surcharge — POS-TXN-90001</td><td>Added when <code>surcharge</code> &gt; 0</td></tr>
            <tr><td>Line 2 Item</td><td>Surcharge Fee</td><td>Merchant's Surcharge Item</td></tr>
            <tr><td>Line 2 Amount</td><td>$1.66</td><td><code>surcharge</code></td></tr>
            <tr><td>Private Note</td><td>Oil change + filter | Surcharge: $1.66 | Posted via BookSync | Batch: …</td><td><code>memo</code> + surcharge amount + BookSync metadata</td></tr>
        </tbody>
    </table>

    {{-- SURCHARGE --}}
    <h2 id="surcharge">Surcharge</h2>
    <p>Surcharge support is optional and per-merchant. To enable it:</p>
    <ol>
        <li>The merchant toggles <strong>Surcharge Enabled</strong> on their setup page</li>
        <li>They select a <strong>Surcharge Item</strong> — a QBO service/product linked to a Surcharge Income account</li>
    </ol>
    <p>Once enabled, any transaction with <code>"surcharge": 1.66</code> produces a two-line Sales Receipt. The sale amount and surcharge are always kept as separate line items so revenue and surcharge fees post to their respective income accounts independently.</p>
    <div class="warn">If surcharge is <strong>not</strong> enabled for a merchant and a transaction arrives with <code>surcharge &gt; 0</code>, the transaction is immediately marked <code>surcharge_not_enabled</code> and <strong>will not be retried</strong>. Enable surcharge in the merchant setup first, then re-submit.</div>

    {{-- DEDUPLICATION --}}
    <h2 id="deduplication">Deduplication</h2>
    <p><code>reference</code> is a unique key per merchant. Re-sending the same reference returns <code>already_posted</code> with the <code>original_batch_id</code> — no duplicate Sales Receipt is created.</p>
    <p>If a transaction is <code>failed</code> or <code>permanently_failed</code>, re-submitting it in a new batch <strong>resets and re-queues it</strong> automatically — this is the intended retry mechanism.</p>

    {{-- RETRIES --}}
    <h2 id="retries">Retry Logic</h2>
    <p>Transient failures (QBO API errors, network timeouts) are retried automatically:</p>
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
    <p>After 7 attempts the transaction becomes <span class="pill pill-red">permanently_failed</span>. Re-submit the reference in a new batch to retry.</p>
    <div class="note">
        <strong>The following errors are never retried</strong> — they require a human fix before resubmission:
        <ul style="margin-top:8px;">
            <li><code>customer_not_found</code> — create the customer in QBO first</li>
            <li><code>payment_method_not_found</code> — create the PaymentMethod in QBO first</li>
            <li><code>surcharge_not_enabled</code> — enable surcharge in the merchant setup first</li>
        </ul>
    </div>

    {{-- ERRORS --}}
    <h2 id="errors">HTTP Error Reference</h2>
    <table>
        <thead><tr><th>Code</th><th>Meaning</th><th>Action</th></tr></thead>
        <tbody>
            <tr><td><code>400</code></td><td>Missing or malformed request fields</td><td>Fix the payload and resend</td></tr>
            <tr><td><code>401</code></td><td>Invalid API key, missing/invalid signature, or stale timestamp</td><td>Check all three auth headers; see <a href="#auth-posting">Posting Authentication</a></td></tr>
            <tr><td><code>403</code></td><td>Merchant not active</td><td>Check <code>status</code> in the error body</td></tr>
            <tr><td><code>404</code></td><td>Merchant token or batch ID not found</td><td>Verify the <code>posting_url</code> or <code>batch_id</code></td></tr>
            <tr><td><code>409</code></td><td>Every transaction in the batch was already posted</td><td>No action — safe duplicate, all skipped</td></tr>
            <tr><td><code>422</code></td><td>Validation error (negative amount, bad date format, etc.)</td><td>Check <code>errors</code> object in the response</td></tr>
            <tr><td><code>500</code></td><td>BookSync internal error</td><td>Transactions retried automatically</td></tr>
            <tr><td><code>502/503</code></td><td>QuickBooks API unavailable</td><td>Transactions retried automatically</td></tr>
        </tbody>
    </table>

    <div class="code-wrap">
        <div class="code-label red">401 — Invalid signature</div>
        <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
            <pre>{ "error": "Invalid signature." }</pre>
        </div>
    </div>
    <div class="code-wrap">
        <div class="code-label red">401 — Stale timestamp</div>
        <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
            <pre>{
  "error":  "Request timestamp is too old or too far in the future.",
  "detail": "Timestamp drift is 412s; maximum allowed is 300s."
}</pre>
        </div>
    </div>
    <div class="code-wrap">
        <div class="code-label red">403 — Merchant not active</div>
        <div class="code-block"><button class="copy-btn" onclick="cp(this)">Copy</button>
            <pre>{
  "error":  "Merchant is not active.",
  "status": "qb_token_expired",
  "detail": "The merchant's QuickBooks token has expired. The merchant must reconnect."
}</pre>
        </div>
    </div>

    <hr>
    <p style="font-size:13px;color:#9ca3af;text-align:center;padding-bottom:48px;">BookSync Integration Guide v2 · Questions? Contact your account manager.</p>

</main>
</div>

<script>
    function cp(btn) {
        const pre = btn.nextElementSibling;
        navigator.clipboard.writeText(pre.textContent.trim()).then(() => {
            btn.textContent = 'Copied!';
            btn.classList.add('ok');
            setTimeout(() => { btn.textContent = 'Copy'; btn.classList.remove('ok'); }, 2000);
        });
    }

    const sections = document.querySelectorAll('[id]');
    const links    = document.querySelectorAll('.sidebar a');
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
