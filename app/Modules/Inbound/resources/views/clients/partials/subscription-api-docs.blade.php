{{-- Subscription API docs (Zoho CRM clients). Expects $baseUrl. Styles come from api-docs.blade.php. --}}
<section class="panel" style="padding:0;overflow:hidden;">
<div class="doc-nav">
    <a href="#auth" class="active" onclick="setActive(this)">Authentication</a>
    <a href="#flow" onclick="setActive(this)">How it works</a>
    <a href="#endpoints" onclick="setActive(this)">Endpoints</a>
    <a href="#errors" onclick="setActive(this)">Errors</a>
</div>

<div class="doc-body">

    {{-- Authentication --}}
    <div class="doc-section" id="auth">
        <h2>Authentication</h2>
        <p style="font-size:13px;color:#6b7280;margin:0 0 16px;line-height:1.65;">
            Send your subscription API key as a bearer token on every request. Keep it on your server; never put it in browser or mobile code.
            Requests are limited to 60 per minute.
        </p>
        <table class="doc-table">
            <tr><th>Header</th><th>Value</th><th>Required</th></tr>
            <tr><td><code>Authorization</code></td><td><code>Bearer &lt;api_key&gt;</code></td><td><span class="badge-req">Required</span></td></tr>
            <tr><td><code>Content-Type</code></td><td><code>application/json</code></td><td><span class="badge-req">Required</span></td></tr>
            <tr><td><code>Accept</code></td><td><code>application/json</code></td><td><span class="badge-opt">Recommended</span></td></tr>
        </table>
    </div>

    {{-- How it works --}}
    <div class="doc-section" id="flow">
        <h2>How it works</h2>
        <ul class="flow-steps">
            <li><span class="step-num">1</span> Your CRM calls <code>POST /subscribe</code> with the customer and the monthly amount.</li>
            <li><span class="step-num">2</span> We create the subscription and immediately email the customer a payment link for the full amount. The same link is returned as <code>payment_link</code>.</li>
            <li><span class="step-num">3</span> On the 1st of every following month, we email the customer a new payment link for that month.</li>
            <li><span class="step-num">4</span> Status is <code>pending_payment</code> until the first payment, then <code>active</code>.</li>
            <li><span class="step-num">5</span> To change the monthly amount, your CRM calls <code>POST /update-amount</code>. The new amount applies from the next payment link; links already sent keep their amount.</li>
            <li><span class="step-num">6</span> Your CRM calls <code>POST /unsubscribe</code> to cancel. Links already sent stay payable until <code>effective_date</code> (end of the current month); no new links are sent.</li>
        </ul>
        <div class="info-box">
            Billing is always monthly; there is no billing cycle to send. A new link is sent each month even if an earlier month is still unpaid.
            A customer can have one open subscription at a time; after cancelling, they can subscribe again.
        </div>
    </div>

    {{-- Endpoints --}}
    <div class="doc-section" id="endpoints">
        <h2>Endpoints</h2>

        {{-- POST /subscribe --}}
        <div class="endpoint-card open" id="ep-subscribe">
            <div class="ep-header" onclick="toggleEp('ep-subscribe')">
                <span class="method-badge method-post">POST</span>
                <span class="ep-url">/api/v1/subscriptions/subscribe</span>
                <span class="ep-desc">Create a subscription and send the first payment link</span>
                <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
            </div>
            <div class="ep-body">
                <p class="section-label">Request Body</p>
                <table class="doc-table">
                    <tr><th>Field</th><th>Type</th><th>Description</th><th>Required</th></tr>
                    <tr><td><code>customer_email</code></td><td>email</td><td>Where payment links are sent. Stored in lowercase.</td><td><span class="badge-req">Required</span></td></tr>
                    <tr><td><code>customer_name</code></td><td>string</td><td>Shown on the payment page and email. Max 255.</td><td><span class="badge-opt">Optional</span></td></tr>
                    <tr><td><code>customer_unique_id</code></td><td>string</td><td>Your CRM's ID for the customer. Strongly recommended: it identifies the customer for duplicate checks and unsubscribe. Max 255.</td><td><span class="badge-opt">Optional</span></td></tr>
                    <tr><td><code>subscription_amount</code></td><td>decimal</td><td>Monthly amount in USD, up to 2 decimals. <code>0.50</code> – <code>999999.99</code>.</td><td><span class="badge-req">Required</span></td></tr>
                </table>

                <p class="code-label label-req">Example Request</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>POST {{ $baseUrl }}/api/v1/subscriptions/subscribe
Authorization: Bearer &lt;api_key&gt;
Content-Type: application/json

{
  "customer_email": "jane.doe@example.com",
  "customer_name": "Jane Doe",
  "customer_unique_id": "CUST-10045",
  "subscription_amount": 199.00
}</pre>
                </div>

                <p class="code-label label-ok">201 — Created</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "success": true,
  "message": "Subscription created successfully.",
  "data": {
    "simphub_reference_id": "SH-SUB-000123",
    "customer_email": "jane.doe@example.com",
    "customer_name": "Jane Doe",
    "customer_unique_id": "CUST-10045",
    "subscription_amount": 199,
    "billing_cycle": "monthly",
    "status": "pending_payment",
    "payment_link": "{{ $baseUrl }}/pay/3f1c9a52-...",
    "created_at": "2026-10-01T14:30:00Z"
  }
}</pre>
                </div>
                <p style="font-size:12px;color:#6b7280;margin:8px 0 0;line-height:1.6;">
                    Store <code>simphub_reference_id</code> in your CRM; it is the most reliable way to unsubscribe later.
                    Fields you did not send (e.g. <code>customer_unique_id</code>) are omitted from the response.
                </p>

                <p class="section-label" style="margin-top:16px;">Errors</p>
                <table class="doc-table">
                    <tr><th>Status</th><th>When</th></tr>
                    <tr><td><span class="status-4xx">401</span></td><td>Missing or invalid API key.</td></tr>
                    <tr><td><span class="status-4xx">409</span></td><td>Customer already has an open subscription. Matched on <code>customer_unique_id</code> if sent, otherwise on <code>customer_email</code>.</td></tr>
                    <tr><td><span class="status-4xx">422</span></td><td>Missing or invalid email or amount.</td></tr>
                    <tr><td><span class="status-5xx">500</span></td><td>Unexpected error. Please try again later.</td></tr>
                </table>
            </div>
        </div>

        {{-- POST /update-amount --}}
        <div class="endpoint-card open" id="ep-update-amount">
            <div class="ep-header" onclick="toggleEp('ep-update-amount')">
                <span class="method-badge method-post">POST</span>
                <span class="ep-url">/api/v1/subscriptions/update-amount</span>
                <span class="ep-desc">Change a customer's monthly amount from the next cycle</span>
                <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
            </div>
            <div class="ep-body">
                <p class="ep-desc-text">
                    Sets a new monthly amount. The next payment link (sent on <code>effective_from</code>, the 1st of next month) uses it.
                    Links already sent, including this month's, keep the amount they were sent with. The customer is not emailed about the change.
                    Identify the subscription the same way as unsubscribe: <code>simphub_reference_id</code>, then <code>customer_unique_id</code>, then <code>customer_email</code> + <code>customer_name</code>.
                </p>

                <p class="section-label">Request Body</p>
                <table class="doc-table">
                    <tr><th>Field</th><th>Type</th><th>Description</th><th>Required</th></tr>
                    <tr><td><code>simphub_reference_id</code></td><td>string</td><td>Returned by subscribe, e.g. <code>SH-SUB-000123</code>.</td><td><span class="badge-opt">Optional</span></td></tr>
                    <tr><td><code>customer_unique_id</code></td><td>string</td><td>The ID you sent to subscribe.</td><td><span class="badge-opt">Optional</span></td></tr>
                    <tr><td><code>customer_email</code></td><td>email</td><td>Required if neither ID above is sent.</td><td><span class="badge-opt">Conditional</span></td></tr>
                    <tr><td><code>customer_name</code></td><td>string</td><td>Required if neither ID above is sent.</td><td><span class="badge-opt">Conditional</span></td></tr>
                    <tr><td><code>subscription_amount</code></td><td>decimal</td><td>New monthly amount in USD, up to 2 decimals. <code>0.50</code> – <code>999999.99</code>.</td><td><span class="badge-req">Required</span></td></tr>
                </table>

                <p class="code-label label-req">Example Request</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>POST {{ $baseUrl }}/api/v1/subscriptions/update-amount
Authorization: Bearer &lt;api_key&gt;
Content-Type: application/json

{
  "customer_unique_id": "CUST-10045",
  "subscription_amount": 30.00
}</pre>
                </div>

                <p class="code-label label-ok">200 — OK</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "success": true,
  "message": "Subscription amount updated successfully.",
  "data": {
    "simphub_reference_id": "SH-SUB-000123",
    "customer_email": "jane.doe@example.com",
    "customer_name": "Jane Doe",
    "customer_unique_id": "CUST-10045",
    "previous_amount": 20,
    "subscription_amount": 30,
    "billing_cycle": "monthly",
    "status": "active",
    "effective_from": "2026-11-01"
  }
}</pre>
                </div>
                <p style="font-size:12px;color:#6b7280;margin:8px 0 0;line-height:1.6;">
                    Sending the same amount again returns 200 and changes nothing. Calling it twice before the next cycle keeps the last amount.
                </p>

                <p class="section-label" style="margin-top:16px;">Errors</p>
                <table class="doc-table">
                    <tr><th>Status</th><th>When</th></tr>
                    <tr><td><span class="status-4xx">401</span></td><td>Missing or invalid API key.</td></tr>
                    <tr><td><span class="status-4xx">404</span></td><td>No subscription for this customer.</td></tr>
                    <tr><td><span class="status-4xx">409</span></td><td>Subscription is cancelled. Subscribe the customer again instead.</td></tr>
                    <tr><td><span class="status-4xx">422</span></td><td>Missing or invalid amount or customer details, or more than one open subscription matches email + name.</td></tr>
                    <tr><td><span class="status-5xx">500</span></td><td>Unexpected error. Please try again later.</td></tr>
                </table>
            </div>
        </div>

        {{-- POST /unsubscribe --}}
        <div class="endpoint-card open" id="ep-unsubscribe">
            <div class="ep-header" onclick="toggleEp('ep-unsubscribe')">
                <span class="method-badge method-post">POST</span>
                <span class="ep-url">/api/v1/subscriptions/unsubscribe</span>
                <span class="ep-desc">Cancel the customer's active subscription</span>
                <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
            </div>
            <div class="ep-body">
                <p class="ep-desc-text">
                    Identify the subscription with <strong>one</strong> of the following, checked in this order:
                    <code>simphub_reference_id</code>, then <code>customer_unique_id</code>, then <code>customer_email</code> + <code>customer_name</code>.
                    Email and name matching ignores upper/lower case, but the name must otherwise match what was sent to subscribe.
                </p>

                <p class="section-label">Request Body</p>
                <table class="doc-table">
                    <tr><th>Field</th><th>Type</th><th>Description</th><th>Required</th></tr>
                    <tr><td><code>simphub_reference_id</code></td><td>string</td><td>Returned by subscribe, e.g. <code>SH-SUB-000123</code>.</td><td><span class="badge-opt">Optional</span></td></tr>
                    <tr><td><code>customer_unique_id</code></td><td>string</td><td>The ID you sent to subscribe.</td><td><span class="badge-opt">Optional</span></td></tr>
                    <tr><td><code>customer_email</code></td><td>email</td><td>Required if neither ID above is sent.</td><td><span class="badge-opt">Conditional</span></td></tr>
                    <tr><td><code>customer_name</code></td><td>string</td><td>Required if neither ID above is sent.</td><td><span class="badge-opt">Conditional</span></td></tr>
                </table>

                <p class="code-label label-req">Example Request</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>POST {{ $baseUrl }}/api/v1/subscriptions/unsubscribe
Authorization: Bearer &lt;api_key&gt;
Content-Type: application/json

{
  "customer_email": "jane.doe@example.com",
  "customer_name": "Jane Doe"
}</pre>
                </div>

                <p class="code-label label-ok">200 — OK</p>
                <div class="code-block">
                    <button class="copy-code" onclick="copyCode(this)">Copy</button>
                    <pre>{
  "success": true,
  "message": "Subscription cancelled successfully.",
  "data": {
    "simphub_reference_id": "SH-SUB-000123",
    "customer_email": "jane.doe@example.com",
    "customer_name": "Jane Doe",
    "customer_unique_id": "CUST-10045",
    "status": "cancelled",
    "cancelled_at": "2026-10-01T14:30:00Z",
    "effective_date": "2026-10-31"
  }
}</pre>
                </div>

                <p class="section-label" style="margin-top:16px;">Errors</p>
                <table class="doc-table">
                    <tr><th>Status</th><th>When</th></tr>
                    <tr><td><span class="status-4xx">401</span></td><td>Missing or invalid API key.</td></tr>
                    <tr><td><span class="status-4xx">404</span></td><td>No subscription for this customer.</td></tr>
                    <tr><td><span class="status-4xx">409</span></td><td>Subscription already cancelled.</td></tr>
                    <tr><td><span class="status-4xx">422</span></td><td>Missing or invalid email / name, or more than one open subscription matches email + name (send <code>customer_unique_id</code> or <code>simphub_reference_id</code> instead).</td></tr>
                    <tr><td><span class="status-5xx">500</span></td><td>Unexpected error. Please try again later.</td></tr>
                </table>
            </div>
        </div>
    </div>

    {{-- Errors --}}
    <div class="doc-section" id="errors">
        <h2>Errors</h2>
        <div class="info-box">
            Errors return <code>"success": false</code> and a <code>message</code>. Validation errors (422) also include an <code>errors</code> object listing each invalid field. A 429 (rate limit) response contains only a <code>message</code>.
        </div>
        <div class="code-block">
            <button class="copy-code" onclick="copyCode(this)">Copy</button>
            <pre>{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "customer_email": ["The customer email field must be a valid email address."]
  }
}</pre>
        </div>
        <table class="doc-table" style="margin-top:12px;">
            <tr><th>Status</th><th>Meaning</th><th>Retry?</th></tr>
            <tr><td><span class="status-4xx">401</span></td><td>Missing or invalid API key.</td><td>No — fix the key.</td></tr>
            <tr><td><span class="status-4xx">404</span></td><td>Subscription not found.</td><td>No.</td></tr>
            <tr><td><span class="status-4xx">409</span></td><td>Conflict with the current subscription state.</td><td>No.</td></tr>
            <tr><td><span class="status-4xx">422</span></td><td>Invalid request body.</td><td>No — fix the request.</td></tr>
            <tr><td><span class="status-4xx">429</span></td><td>Rate limit exceeded (60 / minute).</td><td>Yes, after the <code>Retry-After</code> header.</td></tr>
            <tr><td><span class="status-5xx">500</span></td><td>Unexpected server error.</td><td>Yes, with backoff. A retried subscribe returns 409 if the first attempt succeeded.</td></tr>
        </table>
    </div>

</div>
</section>
