<x-inbound::layouts.master>
<style>
    body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background:#f7f8fa; margin:0; }

    .api-header { margin-bottom:24px; }
    .api-header h2 { font-size:22px; margin:0 0 4px; color:#111827; }
    .api-header p  { font-size:14px; color:#6b7280; margin:0; }

    .client-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px 20px; margin-bottom:24px; display:flex; gap:32px; flex-wrap:wrap; align-items:center; }
    .client-meta label { font-size:11px; font-weight:600; color:#9ca3af; text-transform:uppercase; letter-spacing:.06em; display:block; margin-bottom:4px; }
    .copy-row { display:flex; align-items:center; gap:8px; }
    .copy-row code { font-family:ui-monospace,monospace; font-size:12px; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:6px; padding:5px 10px; color:#374151; }
    .copy-btn-sm { padding:4px 10px; background:#2563eb; color:#fff; border:none; border-radius:6px; font-size:12px; font-weight:500; cursor:pointer; transition:background .15s; white-space:nowrap; }
    .copy-btn-sm:hover { background:#1d4ed8; }
    .copy-btn-sm.copied { background:#10b981; }
    .gw-badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:12px; font-weight:600; background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; margin-right:4px; }


    .endpoint { background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; }
    .endpoint-header { display:flex; align-items:center; gap:14px; padding:16px 20px; cursor:pointer; user-select:none; }
    .endpoint-header:hover { background:#f9fafb; }
    .method { display:inline-flex; align-items:center; justify-content:center; width:58px; padding:4px 0; border-radius:6px; font-size:11px; font-weight:700; letter-spacing:.04em; flex-shrink:0; background:#fef3c7; color:#92400e; }
    .endpoint-url { font-family:ui-monospace,monospace; font-size:13px; color:#111827; font-weight:500; }
    .endpoint-desc { font-size:13px; color:#6b7280; }
    .chevron { margin-left:auto; flex-shrink:0; color:#9ca3af; transition:transform .2s; }
    .endpoint.open .chevron { transform:rotate(180deg); }

    .endpoint-body { display:none; border-top:1px solid #f3f4f6; padding:24px; }
    .endpoint.open .endpoint-body { display:block; }
    .desc { font-size:13px; color:#6b7280; margin:0 0 24px; line-height:1.65; }

    .section-label { font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.08em; margin:0 0 8px; }
    table { width:100%; border-collapse:collapse; font-size:13px; margin-bottom:22px; }
    th { text-align:left; padding:7px 12px; background:#f9fafb; color:#6b7280; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; border-bottom:1px solid #e5e7eb; }
    td { padding:9px 12px; border-bottom:1px solid #f3f4f6; color:#374151; vertical-align:top; }
    td code { font-family:ui-monospace,monospace; font-size:12px; background:#f3f4f6; padding:2px 6px; border-radius:4px; }
    .req  { display:inline-block; padding:1px 7px; border-radius:999px; font-size:10px; font-weight:600; background:#fee2e2; color:#991b1b; }
    .opt  { display:inline-block; padding:1px 7px; border-radius:999px; font-size:10px; font-weight:600; background:#f3f4f6; color:#6b7280; }

    .two-col { display:grid; grid-template-columns:1fr; gap:16px; }

    .code-block { background:#1e293b; border-radius:8px; padding:16px; overflow-x:auto; position:relative; margin-bottom:4px; }
    .code-block pre { margin:0; font-family:ui-monospace,monospace; font-size:12px; color:#e2e8f0; line-height:1.65; white-space:pre; }
    .copy-code { position:absolute; top:10px; right:10px; padding:3px 10px; background:#334155; color:#94a3b8; border:none; border-radius:5px; font-size:11px; cursor:pointer; transition:all .15s; }
    .copy-code:hover { background:#475569; color:#fff; }
    .copy-code.copied { background:#10b981; color:#fff; }

    .response-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; margin:0 0 8px; }
    .ok   { color:#10b981; }
    .err  { color:#ef4444; }


    .actions { margin-top:28px; }
    .btn { padding:10px 20px; border-radius:8px; font-size:14px; cursor:pointer; border:1px solid #d1d5db; background:#fff; font-weight:500; text-decoration:none; color:#374151; display:inline-block; }
</style>

<div class="shell">
    <section class="panel">

        <div class="api-header">
            <p class="eyebrow">Custom PMS — API Reference</p>
            <h2>{{ $client->client_name }}</h2>
            <p>Use the endpoints below to process Paya ACH payments from your own system.</p>
        </div>

        {{-- Client identity --}}
        <div class="client-card">
            <div class="client-meta">
                <label>PMS Client ID <span style="font-weight:400;">(use in request body)</span></label>
                <div class="copy-row">
                    <code id="pms-client-id">{{ $client->pms_client_id }}</code>
                    <button class="copy-btn-sm" onclick="copyText('pms-client-id', this)">Copy</button>
                </div>
            </div>
            <div class="client-meta">
                <label>Base URL</label>
                <div class="copy-row">
                    <code id="base-url">{{ $baseUrl }}</code>
                    <button class="copy-btn-sm" onclick="copyText('base-url', this)">Copy</button>
                </div>
            </div>
            <div class="client-meta">
                <label>Allowed Gateways</label>
                <div>
                    @forelse($client->allowed_payment_gateways ?? [] as $gw)
                        <span class="gw-badge">{{ $gw }}</span>
                    @empty
                        <span style="color:#9ca3af;font-size:13px;">None configured</span>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Endpoint: Tokenize --}}
        <div class="endpoint" id="ep-tokenize" style="margin-bottom:14px;">
            <div class="endpoint-header" onclick="toggleEndpoint('ep-tokenize')">
                <span class="method">POST</span>
                <span class="endpoint-url">/api/v1/payment/direct/{merchantId}/tokenize</span>
                <span class="endpoint-desc">Get a Paya vault token for a bank account</span>
                <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
            </div>
            <div class="endpoint-body">
                <p class="desc">
                    Submits bank account details to Paya and returns a vault token. The token can then be passed to the charge endpoint for payment — raw account details never need to travel with the payment request.
                    Use <code>merchantId</code> = your PMS Client ID.
                </p>

                <p class="section-label">URL Parameter</p>
                <table>
                    <tr><th>Parameter</th><th>Description</th><th></th></tr>
                    <tr><td><code>merchantId</code></td><td>Your PMS Client ID (see above)</td><td><span class="req">Required</span></td></tr>
                </table>

                <p class="section-label">Request Body</p>
                <table>
                    <tr><th>Field</th><th>Type</th><th>Description</th><th></th></tr>
                    <tr><td><code>routing_number</code></td><td>string</td><td>9-digit ABA routing number</td><td><span class="req">Required</span></td></tr>
                    <tr><td><code>account_number</code></td><td>string</td><td>4–17 digit bank account number</td><td><span class="req">Required</span></td></tr>
                    <tr><td><code>account_type</code></td><td>string</td><td><code>checking</code> or <code>savings</code></td><td><span class="opt">Optional</span> — default <code>checking</code></td></tr>
                    <tr><td><code>first_name</code></td><td>string</td><td>Account holder first name</td><td><span class="opt">Optional</span></td></tr>
                    <tr><td><code>last_name</code></td><td>string</td><td>Account holder last name</td><td><span class="opt">Optional</span></td></tr>
                    <tr><td><code>address1</code></td><td>string</td><td>Billing address line 1</td><td><span class="opt">Optional</span></td></tr>
                    <tr><td><code>city</code></td><td>string</td><td>Billing city</td><td><span class="opt">Optional</span></td></tr>
                    <tr><td><code>state</code></td><td>string</td><td>2-letter state code</td><td><span class="opt">Optional</span></td></tr>
                    <tr><td><code>zip</code></td><td>string</td><td>ZIP / postal code</td><td><span class="opt">Optional</span></td></tr>
                    <tr><td><code>phone_number</code></td><td>string</td><td>Account holder phone</td><td><span class="opt">Optional</span></td></tr>
                </table>

                <div class="two-col">
                    <div>
                        <p class="section-label response-label">Example Request</p>
                        <div class="code-block">
                            <button class="copy-code" onclick="copyCode(this)">Copy</button>
                            <pre>POST {{ $baseUrl }}/api/v1/payment/direct/{{ $client->pms_client_id }}/tokenize
Content-Type: application/json

{
  "routing_number": "490000018",
  "account_number": "123456789",
  "account_type": "checking"
}</pre>
                        </div>
                    </div>
                    <div>
                        <p class="section-label response-label ok">200 — Token Issued</p>
                        <div class="code-block">
                            <button class="copy-code" onclick="copyCode(this)">Copy</button>
                            <pre>{
  "token": "PAYA_VAULT_TOKEN",
  "account_type": "checking",
  "last4": "6789"
}</pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Endpoint: Charge --}}
        <div class="endpoint" id="ep-charge">
            <div class="endpoint-header" onclick="toggleEndpoint('ep-charge')">
                <span class="method">POST</span>
                <span class="endpoint-url">/api/v1/payment/direct/{merchantId}/charge</span>
                <span class="endpoint-desc">Process a Paya ACH payment using a vault token</span>
                <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
            </div>

            <div class="endpoint-body">
                <p class="desc">
                    Submit a payment directly through Paya (ACH) using a vault token obtained from the tokenize endpoint.
                    The system automatically selects the correct routing rule for your merchant account.
                    No invoice or payment session is created; use the single-call flow above if you need an invoice record.
                </p>

                <p class="section-label">URL Parameter</p>
                <table>
                    <tr><th>Parameter</th><th>Description</th><th></th></tr>
                    <tr><td><code>merchantId</code></td><td>Your PMS Client ID (see above)</td><td><span class="req">Required</span></td></tr>
                </table>

                <p class="section-label">Headers</p>
                <table>
                    <tr><th>Header</th><th>Value</th><th></th></tr>
                    <tr><td><code>Content-Type</code></td><td><code>application/json</code></td><td><span class="req">Required</span></td></tr>
                </table>

                <p class="section-label">Request Body</p>
                <table>
                    <tr><th>Field</th><th>Type</th><th>Description</th><th></th></tr>
                    <tr><td><code>token</code></td><td>string</td><td>Paya vault token from the tokenize endpoint</td><td><span class="req">Required</span></td></tr>
                    <tr><td><code>amount_cents</code></td><td>integer</td><td>Payment amount in cents — e.g. <code>150000</code> = $1,500.00</td><td><span class="req">Required</span></td></tr>
                    <tr><td><code>payment_method</code></td><td>string</td><td><code>ACH</code> or <code>CARD</code></td><td><span class="opt">Optional</span> — default <code>ACH</code></td></tr>
                    <tr><td><code>fund_type</code></td><td>string</td><td><code>OPERATING</code> or <code>TRUST</code></td><td><span class="opt">Optional</span> — default <code>OPERATING</code></td></tr>
                    <tr><td><code>currency</code></td><td>string</td><td>ISO 4217 currency code</td><td><span class="opt">Optional</span> — default <code>USD</code></td></tr>
                </table>

                <div class="two-col">
                    <div>
                        <p class="section-label response-label">Example Request</p>
                        <div class="code-block">
                            <button class="copy-code" onclick="copyCode(this)">Copy</button>
                            <pre>POST {{ $baseUrl }}/api/v1/payment/direct/{{ $client->pms_client_id }}/charge
Content-Type: application/json

{
  "token": "PAYA_VAULT_TOKEN",
  "amount_cents": 150000,
  "payment_method": "ACH",
  "fund_type": "OPERATING",
  "currency": "USD"
}</pre>
                        </div>
                    </div>
                    <div>
                        <p class="section-label response-label ok">200 — Approved</p>
                        <div class="code-block">
                            <button class="copy-code" onclick="copyCode(this)">Copy</button>
                            <pre>{
  "status": "APPROVED",
  "gateway_txn_id": "paya-txn-abc123",
  "gateway": "paya",
  "amount_cents": 150000,
  "currency": "USD",
  "payment_method": "ACH"
}</pre>
                        </div>

                        <p class="section-label response-label err" style="margin-top:14px;">422 — Declined</p>
                        <div class="code-block">
                            <button class="copy-code" onclick="copyCode(this)">Copy</button>
                            <pre>{
  "status": "DECLINED",
  "message": "Insufficient funds",
  "gateway": "paya"
}</pre>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </section>
</div>

<script>
    function toggleEndpoint(id) {
        document.getElementById(id).classList.toggle('open');
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
</script>
</x-inbound::layouts.master>
