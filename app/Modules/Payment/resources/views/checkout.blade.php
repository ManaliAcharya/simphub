<x-payment::layouts.master :title="!empty($clientName) ? $clientName . ' - Secure Payment' : null">
    @if(!empty($logoUrl))
    <div style="background:#ffffff;border-bottom:1px solid #e5e7eb;padding:14px 24px;text-align:center;">
        <img src="{{ $logoUrl }}" alt="Company Logo"
             style="max-height:56px;max-width:220px;object-fit:contain;display:inline-block;">
    </div>
    @endif
    <div class="shell">
        <section class="panel hero">
            <p class="eyebrow">Secure Invoice Payment</p>
            <h2 id="invoice-title">Loading invoice...</h2>
            <p id="invoice-copy">We are preparing your secure payment session.</p>
            <div class="summary">
                <div>
                    <span>Amount due</span>
                    <strong id="invoice-amount">--</strong>
                </div>
                <div>
                    <span>Status</span>
                    <strong id="session-status">--</strong>
                </div>
            </div>
        </section>

        <section class="panel checkout">
            <h2 style="margin:0 0 16px;">How would you like to pay?</h2>

            {{-- Payment option tiles (rendered by JS) --}}
            <div id="gateway-options" style="display:flex;gap:10px;flex-wrap:wrap;"></div>

            {{-- Disclosure text --}}
            <p id="fee-disclosure-text" style="display:none;margin:12px 0 0;font-size:12px;color:#6b7280;line-height:1.5;"></p>

            <div id="card-panel" class="card-panel hidden">
                <!-- <h3>Enter card details in the secure gateway fields</h3> -->
                <!-- <p id="gateway-mode" class="muted"></p> -->

                <div id="cardholder-fields" class="hidden" style="margin-bottom:14px;">
                    <div class="field-row">
                        <div class="field-plain">
                            <label>First name</label>
                            <input id="cardholder-first-name" type="text" autocomplete="given-name" />
                        </div>
                        <div class="field-plain">
                            <label>Last name</label>
                            <input id="cardholder-last-name" type="text" autocomplete="family-name" />
                        </div>
                    </div>
                    <div style="margin-top:8px;">
                        <div class="field-plain">
                            <label>Billing address</label>
                            <input id="cardholder-address1" type="text" autocomplete="billing address-line1" required />
                        </div>
                        <div class="field-row" style="margin-top:8px;">
                            <div class="field-plain">
                                <label>City</label>
                                <input id="cardholder-city" type="text" autocomplete="billing address-level2" required />
                            </div>
                            <div class="field-plain">
                                <label>State</label>
                                <input id="cardholder-state" type="text" maxlength="2" autocomplete="billing address-level1" required />
                            </div>
                        </div>
                        <div class="field-plain" style="margin-top:8px;">
                            <label>ZIP</label>
                            <input id="cardholder-zip" type="text" autocomplete="billing postal-code" required />
                        </div>
                    </div>
                </div>

                <div id="hosted-fields" class="hosted-fields hidden">
                    <div class="field">
                        <label>Card number</label>
                        <div id="ccnumber"></div>
                    </div>
                    <div class="field-row">
                        <div class="field">
                            <label>Expiry</label>
                            <div id="ccexp"></div>
                        </div>
                        <div class="field">
                            <label>CVV</label>
                            <div id="cvv"></div>
                        </div>
                    </div>
                    <button id="tokenize-button" class="primary-button" type="button">Securely tokenize card</button>
                </div>

                <div id="fluidpay-panel" class="hidden">
                    <div class="field">
                        <label>Card details</label>
                        <div id="fluidpay-payment-form"></div>
                    </div>
                </div>

                <div id="mock-token-panel" class="mock-panel hidden">
                    <label for="token-input">Gateway token</label>
                    <input id="token-input" type="text" placeholder="tok_demo_123" />
                    <p class="muted">A hosted tokenizer is not configured in this environment, so a gateway token can be entered directly for testing.</p>
                </div>

                <div id="paya-ach-panel" class="hidden">
                    {{-- Custom bank entry form (default — shown when no Paya developer ID) --}}
                    <div id="paya-custom-form">
                        <p class="muted" style="margin-bottom:16px;">Enter your bank account details to complete payment.</p>
                        <div class="field">
                            <label>Routing Number</label>
                            <input id="paya-routing" type="text" inputmode="numeric" maxlength="9" placeholder="9-digit routing number" />
                        </div>
                        <div class="field">
                            <label>Account Number</label>
                            <input id="paya-account" type="text" inputmode="numeric" placeholder="Account number" />
                        </div>
                        <div class="field">
                            <label>Account Type</label>
                            <select id="paya-account-type" style="width:100%;padding:12px 14px;border-radius:12px;border:1px solid rgba(16,33,58,.12);font:inherit;background:#fff;">
                                <option value="checking">Checking</option>
                                <option value="savings">Savings</option>
                            </select>
                        </div>
                    </div>

                    {{-- Paya-hosted AccountForm iframe (shown when PAYA_DEVELOPER_ID is configured) --}}
                    <div id="paya-iframe-wrap" style="display:none;border:1px solid rgba(16,33,58,.1);border-radius:14px;overflow:hidden;min-height:320px;background:#f8f9fb;align-items:center;justify-content:center;">
                        <p class="muted" id="paya-iframe-loading">Loading secure bank entry form…</p>
                        <iframe id="paya-accountform-iframe"
                                style="display:none;width:100%;border:none;min-height:320px;"
                                allowfullscreen></iframe>
                    </div>
                </div>

                <!-- <div id="direct-pay-panel" class="mock-panel hidden">
                    <p class="muted">This gateway charges the invoice amount directly in sandbox mode when you submit the payment.</p>
                </div> -->

            </div>

            {{-- Cash payment details panel (shown when cash tile is selected) --}}
            <div id="cash-details-panel" class="card-panel hidden" style="padding-top:16px;">
                <div id="cash-details-content" style="font-size:14px;color:#374151;display:grid;gap:8px;"></div>
            </div>

            {{-- Submit button — always visible below card/cash panels --}}
            <div class="actions" style="margin-top:16px;">
                <button id="submit-button" class="primary-button" type="button" disabled>Submit payment</button>
                <span id="submit-status" class="muted"></span>
            </div>
        </section>
        {{-- Cash / check instructions page --}}
        <section id="cash-instructions-page" style="display:none;max-width:520px;margin:0 auto;">
            <div class="panel" style="padding:1.75rem;">

                <h2 style="margin:0 0 10px;font-size:20px;">Pay by cash or check</h2>
                <p style="margin:0 0 1.25rem;font-size:14px;color:var(--muted);line-height:1.6;">You've chosen to pay offline. Please follow the instructions below to complete your payment.</p>

                {{-- Invoice amount --}}
                <div style="background:rgba(16,33,58,.04);border-radius:14px;padding:13px 16px;margin-bottom:1.25rem;display:flex;justify-content:space-between;align-items:baseline;">
                    <span style="font-size:13px;color:var(--muted);">Invoice amount</span>
                    <span id="ci-amount" style="font-size:20px;font-weight:600;color:#10213a;">--</span>
                </div>

                {{-- Savings --}}
                <div id="ci-savings" style="display:none;border-radius:14px;padding:13px 16px;margin-bottom:1.25rem;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.2);">
                    <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#15803d;">Your savings by paying offline</p>
                    <div id="ci-savings-rows" style="color:#15803d;"></div>
                </div>

                {{-- Payment instructions --}}
                <div style="border-top:1px solid rgba(16,33,58,.1);padding-top:1.25rem;margin-bottom:1.25rem;">
                    <p style="margin:0 0 14px;font-size:14px;font-weight:600;color:#10213a;">To complete your payment</p>
                    <div style="display:grid;gap:14px;font-size:14px;">
                        <div id="ci-payable-row" style="display:none;">
                            <p style="margin:0;font-size:12px;color:var(--muted);">Make your check payable to</p>
                            <p id="ci-payable" style="margin:3px 0 0;font-weight:600;"></p>
                        </div>
                        <div id="ci-address-row" style="display:none;">
                            <p style="margin:0;font-size:12px;color:var(--muted);">Mail to</p>
                            <p id="ci-address" style="margin:3px 0 0;line-height:1.6;"></p>
                        </div>
                        <div>
                            <p style="margin:0;font-size:12px;color:var(--muted);">Reference / Invoice</p>
                            <p id="ci-reference" style="margin:3px 0 0;font-family:ui-monospace,monospace;font-weight:600;"></p>
                        </div>
                    </div>
                    <div id="ci-instructions" style="display:none;margin-top:14px;background:rgba(37,99,235,.08);border-radius:10px;padding:11px 14px;font-size:13px;color:#1e40af;line-height:1.55;">
                        &#9432; <span id="ci-instructions-text"></span>
                    </div>
                </div>

                {{-- Contact --}}
                <div id="ci-contact" style="display:none;border-top:1px solid rgba(16,33,58,.1);padding-top:1.25rem;margin-bottom:1.25rem;">
                    <p id="ci-contact-label" style="margin:0 0 10px;font-size:13px;color:var(--muted);"></p>
                    <div id="ci-contact-details"></div>
                </div>

                <p style="margin:0 0 1.25rem;font-size:12px;color:var(--muted);text-align:center;line-height:1.55;">Once your payment is received, your invoice will be marked as paid.</p>

                <button onclick="window.location.reload();"
                        class="primary-button" style="width:100%;background:rgba(16,33,58,.07);color:#10213a;">
                    &#8592; Back to payment options
                </button>

            </div>
        </section>

        {{-- Payment confirmation screen (shown after successful submit) --}}
        <section id="payment-confirmation" style="display:none;max-width:520px;margin:0 auto;">
            <div class="panel" style="text-align:center;padding:2rem 1.75rem 1.5rem;">

                {{-- Icon --}}
                <div id="conf-icon" style="width:56px;height:56px;border-radius:50%;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;font-size:26px;"></div>
                <h2 id="conf-title" style="margin:0 0 6px;font-size:22px;"></h2>
                <p id="conf-subtitle" style="margin:0 0 1.5rem;font-size:13px;color:var(--muted);"></p>

                {{-- Details table --}}
                <div style="border-top:1px solid rgba(16,33,58,.1);padding-top:1.25rem;margin-bottom:1.25rem;text-align:left;">
                    <table id="conf-details" style="width:100%;font-size:14px;border-collapse:collapse;"></table>
                </div>

                {{-- Amount breakdown --}}
                <div id="conf-breakdown" style="display:none;background:rgba(16,33,58,.04);border-radius:14px;padding:14px 16px;margin-bottom:1.25rem;text-align:left;"></div>

                {{-- ACH pending notice --}}
                <div id="conf-ach-notice" style="display:none;border-radius:12px;padding:12px 14px;margin-bottom:1.25rem;font-size:13px;line-height:1.55;text-align:left;background:rgba(245,158,11,.1);color:#92400e;">
                    &#9432; ACH payments typically take 2–5 business days to settle. You will receive a confirmation once the payment clears.
                </div>

                {{-- Buttons --}}
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <button id="conf-download" class="primary-button" style="background:rgba(16,33,58,.08);color:#10213a;">&#8681; Download receipt</button>
                    <button id="conf-return"   class="primary-button" style="display:none;">Return to merchant &#8250;</button>
                </div>

            </div>
        </section>

        <div style="text-align:center;padding:20px 0 4px;display:inline-flex;align-items:center;gap:6px;justify-content:center;width:100%;color:var(--muted);font-size:12px;">
            Powered by
            <img src="{{ asset('images/logo/simphub-favicon.jpeg') }}" alt="SimpHub" width="16" height="16" style="height:16px;width:16px;border-radius:3px;vertical-align:middle;">
            <strong style="color:var(--muted);font-weight:600;">SimpHub</strong>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer></script>
    <script>
        window.paymentSessionToken = @json($sessionToken);
        window.feeConfig = @json($feeConfig);
    </script>
    <script>
        const sessionToken = window.paymentSessionToken;
        const state = {
            details: null,
            token: '',
            selectedOption: null,
            cashSelected: false,
            fluidpayTokenizer: null,
            currentScript: null,
        };
        const els = {
            title: document.getElementById('invoice-title'),
            copy: document.getElementById('invoice-copy'),
            amount: document.getElementById('invoice-amount'),
            status: document.getElementById('session-status'),
            gatewayOptions: document.getElementById('gateway-options'),
            cardPanel: document.getElementById('card-panel'),
            cardholderFields: document.getElementById('cardholder-fields'),
            cardholderFirstName: document.getElementById('cardholder-first-name'),
            cardholderLastName: document.getElementById('cardholder-last-name'),
            cardholderAddress1: document.getElementById('cardholder-address1'),
            cardholderCity: document.getElementById('cardholder-city'),
            cardholderState: document.getElementById('cardholder-state'),
            cardholderZip: document.getElementById('cardholder-zip'),
            cashDetailsPanel: document.getElementById('cash-details-panel'),
            cashDetailsContent: document.getElementById('cash-details-content'),
            hostedFields: document.getElementById('hosted-fields'),
            fluidpayPanel: document.getElementById('fluidpay-panel'),
            fluidpayForm: document.getElementById('fluidpay-payment-form'),
            mockTokenPanel: document.getElementById('mock-token-panel'),
            payaAchPanel: document.getElementById('paya-ach-panel'),
            payaCustomForm: document.getElementById('paya-custom-form'),
            payaIframeWrap: document.getElementById('paya-iframe-wrap'),
            payaIframe: document.getElementById('paya-accountform-iframe'),
            payaIframeLoading: document.getElementById('paya-iframe-loading'),
            payaRouting: document.getElementById('paya-routing'),
            payaAccount: document.getElementById('paya-account'),
            payaAccountType: document.getElementById('paya-account-type'),
            tokenInput: document.getElementById('token-input'),
            submitButton: document.getElementById('submit-button'),
            submitStatus: document.getElementById('submit-status'),
            tokenizeButton: document.getElementById('tokenize-button'),
        };

        async function loadPayaAccountForm() {
            // Default: show the custom bank entry form and enable the submit button
            if (els.payaCustomForm) els.payaCustomForm.style.display = 'block';
            if (els.payaIframeWrap) els.payaIframeWrap.style.display = 'none';
            els.submitButton.disabled = false;
            els.submitStatus.textContent = 'Enter your bank details and click Submit payment.';

            // Try to load Paya-hosted AccountForm — only available when PAYA_DEVELOPER_ID is set
            try {
                const res = await fetch(`/api/v1/payment/sessions/${sessionToken}/paya-form-url`);
                const data = await res.json();
                if (!res.ok || !data.url) {
                    // Developer ID not configured — custom form already shown, button enabled
                    return;
                }
                // Developer ID configured — switch to hosted iframe, disable button until token received
                if (els.payaCustomForm) els.payaCustomForm.style.display = 'none';
                if (els.payaIframeWrap) els.payaIframeWrap.style.display = 'flex';
                els.submitButton.disabled = true;
                els.submitStatus.textContent = 'Complete the bank details form above to continue.';
                els.payaIframe.src = data.url;
                els.payaIframe.style.display = 'block';
                if (els.payaIframeLoading) els.payaIframeLoading.style.display = 'none';
            } catch (e) {
                // Network error — custom form shown, button already enabled
            }
        }

        // Listen for Paya AccountForm postMessage (vault token)
        window.addEventListener('message', function(event) {
            if (!event.data || typeof event.data !== 'object') return;

            // Paya sends back account_vault_id or token on success
            const vaultId = event.data.account_vault_id || event.data.id || null;
            if (!vaultId) return;

            state.payaBankToken = vaultId;
            state.token = '__paya_ach__';

            // Shrink iframe, show ready state
            if (els.payaIframe) {
                els.payaIframe.style.minHeight = '60px';
                els.payaIframe.style.opacity   = '0.5';
                els.payaIframe.style.pointerEvents = 'none';
            }
            els.submitButton.disabled = false;
            els.submitStatus.textContent = 'Bank account secured. Click Submit payment to continue.';
        });

        function showConfirmation(payload) {
            // Hide hero + checkout panels, show confirmation
            document.querySelectorAll('.shell > section.panel, .shell > section.panel.hero, .shell > section.panel.checkout')
                .forEach(function(s) { s.style.display = 'none'; });
            const conf = document.getElementById('payment-confirmation');
            if (!conf) return;
            conf.style.display = 'block';

            const config    = window.feeConfig || {};
            const invoice   = state.details ? state.details.invoice : {};
            const option    = state.selectedOption || {};
            const isAch     = (option.payment_method || '').toUpperCase() === 'ACH';
            const fmt       = function(cents) { return '$' + (cents / 100).toFixed(2); };

            // Fee calc
            const baseCents = invoice.amount_cents || 0;
            const feeEnabled = config.fee_surcharge_enabled;
            const feePercent = feeEnabled
                ? parseFloat(isAch ? (config.ach_fee_percent || 0) : (config.cc_fee_percent || 0))
                : 0;
            const feeCents   = feePercent > 0 ? Math.round(baseCents * feePercent / 100) : 0;
            const totalCents = baseCents + feeCents;

            // Icon + title
            const icon = document.getElementById('conf-icon');
            const title = document.getElementById('conf-title');
            const subtitle = document.getElementById('conf-subtitle');
            if (isAch) {
                icon.style.background = 'rgba(245,158,11,.15)';
                icon.style.color = '#92400e';
                icon.innerHTML = '&#9203;';
                title.textContent = 'Payment submitted';
                subtitle.textContent = 'Your ACH payment is pending settlement.';
                document.getElementById('conf-ach-notice').style.display = 'block';
            } else {
                icon.style.background = 'rgba(34,197,94,.15)';
                icon.style.color = '#15803d';
                icon.innerHTML = '&#10003;';
                title.textContent = 'Payment successful';
                subtitle.textContent = 'Your payment has been processed.';
            }

            // Details table
            const invoiceRef = invoice.invoice_number
                ? '#' + invoice.invoice_number
                : (invoice.external_invoice_id || '--');
            const rows = [
                ['Invoice',        invoiceRef],
                config.client_name ? ['Paid to', config.client_name] : null,
                ['Payment via',    (option.display_name || option.gateway || '--').toString().toUpperCase() + ' · ' + (isAch ? 'ACH' : 'Card')],
                ['Transaction ID', payload.gateway_txn_id || '--'],
            ].filter(Boolean);

            const table = document.getElementById('conf-details');
            table.innerHTML = rows.map(function(r) {
                return '<tr>'
                    + '<td style="color:#60708a;padding:5px 0;font-size:14px;">' + r[0] + '</td>'
                    + '<td style="text-align:right;padding:5px 0;font-size:14px;font-weight:500;'
                    + (r[0] === 'Transaction ID' ? 'font-family:ui-monospace,monospace;font-size:12px;' : '')
                    + '">' + r[1] + '</td>'
                    + '</tr>';
            }).join('');

            // Amount breakdown (only if fee applied)
            const breakdown = document.getElementById('conf-breakdown');
            if (feeCents > 0) {
                breakdown.style.display = 'block';
                breakdown.innerHTML = [
                    '<div style="display:flex;justify-content:space-between;font-size:13px;padding:3px 0;">',
                    '<span style="color:#60708a;">Invoice amount</span><span>' + fmt(baseCents) + '</span></div>',
                    '<div style="display:flex;justify-content:space-between;font-size:13px;padding:3px 0;">',
                    '<span style="color:#60708a;">Processing fee (' + feePercent + '%)</span><span>+' + fmt(feeCents) + '</span></div>',
                    '<div style="display:flex;justify-content:space-between;padding:8px 0 0;margin-top:6px;',
                    'border-top:1px solid rgba(16,33,58,.1);font-weight:600;">',
                    '<span>Total charged</span><span>' + fmt(totalCents) + '</span></div>',
                ].join('');
            }

            // Return to merchant button
            const returnBtn = document.getElementById('conf-return');
            const redirectUrl = payload.redirect_url || invoice.success_redirect_url;
            if (redirectUrl) {
                returnBtn.style.display = 'block';
                returnBtn.addEventListener('click', function() { window.location.href = redirectUrl; });
            }

            // Download receipt as PDF
            document.getElementById('conf-download').addEventListener('click', function() {
                var btn = this;
                btn.disabled = true;
                btn.textContent = 'Generating…';

                var panel = document.querySelector('#payment-confirmation .panel');
                if (!panel || typeof html2pdf === 'undefined') {
                    window.print();
                    btn.disabled = false;
                    btn.innerHTML = '&#8681; Download receipt';
                    return;
                }

                // Clone panel so we can add print-only styles without affecting the live page
                var clone = panel.cloneNode(true);
                clone.style.cssText = 'max-width:600px;margin:0;padding:28px;font-family:-apple-system,Segoe UI,Roboto,sans-serif;';

                // Remove interactive buttons from PDF
                clone.querySelectorAll('button').forEach(function(b) { b.remove(); });

                var invoiceRef = document.getElementById('ci-reference')
                    ? document.getElementById('ci-reference').textContent.trim()
                    : 'receipt';
                var filename = 'payment-' + invoiceRef.replace(/[^a-z0-9]/gi, '-') + '.pdf';

                html2pdf()
                    .set({
                        margin:       10,
                        filename:     filename,
                        html2canvas:  { scale: 2, useCORS: true, logging: false },
                        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
                    })
                    .from(clone)
                    .save()
                    .then(function() {
                        btn.disabled = false;
                        btn.innerHTML = '&#8681; Download receipt';
                    });
            });
        }

        function showCashInstructions() {
            document.querySelectorAll('.shell > section').forEach(function(s) { s.style.display = 'none'; });
            const page = document.getElementById('cash-instructions-page');
            if (!page) return;
            page.style.display = 'block';

            const config  = window.feeConfig || {};
            const invoice = state.details ? state.details.invoice : {};
            const cd      = (config.cash_discount_details) || {};
            const options = state.details ? (state.details.payment_options || []) : [];
            const fmt     = function(cents) { return '$' + (cents / 100).toFixed(2); };
            const baseCents = invoice.amount_cents || 0;
            const invoiceRef = invoice.invoice_number
                ? '#' + invoice.invoice_number
                : (invoice.external_invoice_id || '--');

            // Invoice amount
            document.getElementById('ci-amount').textContent = fmt(baseCents);

            // Savings vs each gateway
            const savingsWrap = document.getElementById('ci-savings-rows');
            const feeEnabled  = config.fee_surcharge_enabled;
            if (feeEnabled && options.length) {
                const rows = [];
                options.filter(function(o) { return o.is_available !== false; }).forEach(function(o) {
                    const pm = (o.payment_method || (o.hosted_fields && o.hosted_fields.metadata && o.hosted_fields.metadata.payment_method) || 'CARD').toUpperCase();
                    const pct = parseFloat(pm === 'ACH' ? (config.ach_fee_percent || 0) : (config.cc_fee_percent || 0));
                    if (pct > 0) {
                        const saving = Math.round(baseCents * pct / 100);
                        rows.push('<div style="display:flex;justify-content:space-between;font-size:13px;padding:3px 0;">'
                            + '<span>' + (o.display_name || o.gateway.toUpperCase()) + ' (' + pct + '%)</span>'
                            + '<span style="font-weight:600;">' + fmt(saving) + '</span></div>');
                    }
                });
                if (rows.length) {
                    document.getElementById('ci-savings').style.display = 'block';
                    savingsWrap.innerHTML = rows.join('');
                }
            }

            // Payment instructions
            if (cd.business_name) document.getElementById('ci-payable-row').style.display = 'grid';
            document.getElementById('ci-payable').textContent = cd.business_name || '';

            const addrParts = [cd.address, [cd.city, [cd.state, cd.zip].filter(Boolean).join(' ')].filter(Boolean).join(', ')].filter(Boolean);
            if (addrParts.length) {
                document.getElementById('ci-address-row').style.display = 'grid';
                document.getElementById('ci-address').innerHTML = addrParts.join('<br>');
            }

            document.getElementById('ci-reference').textContent = invoiceRef;

            if (cd.instructions) {
                document.getElementById('ci-instructions').style.display = 'block';
                document.getElementById('ci-instructions-text').textContent = cd.instructions;
            }

            // Contact
            const hasContact = cd.phone || cd.email;
            if (hasContact) {
                document.getElementById('ci-contact').style.display = 'block';
                document.getElementById('ci-contact-label').textContent = 'Questions? Contact ' + (config.client_name || 'the merchant');
                const lines = [];
                if (cd.phone) lines.push('&#9990; ' + cd.phone);
                if (cd.email) lines.push('&#9993; <a href="mailto:' + cd.email + '" style="color:#10213a;">' + cd.email + '</a>');
                document.getElementById('ci-contact-details').innerHTML = lines.map(function(l) {
                    return '<div style="font-size:14px;padding:3px 0;">' + l + '</div>';
                }).join('');
            }
        }

        async function loadDetails() {
            const response = await fetch(`/api/v1/payment/sessions/${sessionToken}`);
            const details = await response.json();
            if (!response.ok) {
                els.title.textContent = details.message || 'This payment link is no longer available.';
                els.copy.textContent = '';
                return;
            }
            state.details = details;

            // Prefill the (editable) billing address from the invoice's billing
            // address on file, if any — a customer's card billing address won't
            // always match, so this is a starting point, not a lock.
            const billingAddress = details.invoice.billing_address || {};
            if (els.cardholderAddress1 && !els.cardholderAddress1.value) els.cardholderAddress1.value = billingAddress.address1 || '';
            if (els.cardholderCity && !els.cardholderCity.value) els.cardholderCity.value = billingAddress.city || '';
            if (els.cardholderState && !els.cardholderState.value) els.cardholderState.value = billingAddress.state || '';
            if (els.cardholderZip && !els.cardholderZip.value) els.cardholderZip.value = billingAddress.zip || '';

            const amount = (details.invoice.amount_cents / 100).toFixed(2);
            els.title.textContent = details.invoice.invoice_number
                ? `Invoice #${details.invoice.invoice_number}`
                : `Invoice ${details.invoice.external_invoice_id}`;
            els.copy.textContent = 'Review the invoice details below and continue to the secure card form.';
            els.amount.textContent = `${amount} ${details.invoice.currency}`;
            els.status.textContent = details.session.status;
            renderOptions(details.payment_options || []);
        }

        function resetToken(message = '') {
            state.token = '';
            els.submitButton.disabled = true;
            els.submitStatus.textContent = message;
        }

        function enableSubmit(token) {
            state.token = token;
            els.submitButton.disabled = !token || !state.selectedOption;
            els.submitStatus.textContent = token ? 'Gateway token ready.' : '';
        }

        function renderOptions(options) {
            els.gatewayOptions.innerHTML = '';
            const config = window.feeConfig || {};
            const feeEnabled = config.fee_surcharge_enabled;
            const baseCents = state.details.invoice.amount_cents;
            const currency  = state.details.invoice.currency || 'USD';
            const fmt = (cents) => '$' + (cents / 100).toFixed(2);

            const availableOptions = options.filter((o) => o.is_available !== false);

            options.forEach((option) => {
                const available = option.is_available !== false;
                const payMethod = (
                    option.payment_method ||
                    (option.hosted_fields && option.hosted_fields.metadata && option.hosted_fields.metadata.payment_method) ||
                    'CARD'
                ).toUpperCase();

                const isAch = payMethod === 'ACH';
                const gwLower = option.gateway.toLowerCase();
                let feePercent = 0;
                if (feeEnabled) {
                    if (config.gateway_rates && config.gateway_rates[gwLower] !== undefined) {
                        // QB multi-MID: per-gateway rate from client_mid_routes
                        feePercent = parseFloat(config.gateway_rates[gwLower] || 0);
                    } else {
                        // Standard: cc/ach fallback
                        feePercent = parseFloat(isAch ? (config.ach_fee_percent || 0) : (config.cc_fee_percent || 0));
                    }
                }
                const feeCents   = feePercent > 0 ? Math.round(baseCents * feePercent / 100) : 0;
                const totalCents = baseCents + feeCents;

                const tile = document.createElement('div');
                tile.className = 'pay-tile' + (available ? '' : ' pay-tile-disabled');
                tile.dataset.routingRuleId = option.routing_rule_id;
                tile.style.cssText = [
                    'display:flex;justify-content:space-between;align-items:center',
                    'padding:14px 16px;border:1px solid #e5e7eb;border-radius:14px',
                    'cursor:' + (available ? 'pointer' : 'default'),
                    'background:#fff;transition:border-color .15s',
                    'flex:1;min-width:180px',
                    available ? '' : 'opacity:0.45',
                ].join(';');

                tile.innerHTML = [
                    '<div>',
                    `  <p style="margin:0;font-size:14px;font-weight:600;color:#10213a;">${(option.display_name || option.gateway.toUpperCase())} <span style="font-size:12px;font-weight:500;color:#60708a;">(${isAch ? 'ACH' : 'CC'})</span></p>`,
                    available
                        ? (feeEnabled && feePercent > 0
                            ? `  <p style="margin:3px 0 0;font-size:12px;color:#6b7c93;">Includes ${feePercent}% processing fee</p>`
                            : `  <p style="margin:3px 0 0;font-size:12px;color:#6b7c93;">${describeOption(option)}</p>`)
                        : `  <p style="margin:3px 0 0;font-size:12px;color:#e24b4a;">${option.unavailable_reason || 'Unavailable'}</p>`,
                    '</div>',
                    `<div style="text-align:right;">`,
                    `  <p style="margin:0;font-size:17px;font-weight:600;color:#10213a;">${fmt(totalCents)}</p>`,
                    `</div>`,
                ].join('');

                if (available) {
                    tile.addEventListener('click', () => selectOption(option, tile));
                    tile.addEventListener('mouseenter', () => { if (!tile.classList.contains('pay-tile-selected')) { tile.style.borderColor = 'rgba(239,131,84,0.5)'; tile.style.transform = 'translateY(-1px)'; } });
                    tile.addEventListener('mouseleave', () => { if (!tile.classList.contains('pay-tile-selected')) { tile.style.borderColor = '#e5e7eb'; tile.style.transform = ''; } });
                }

                els.gatewayOptions.appendChild(tile);
            });

            // Cash / check tile — show whenever cash details are configured
            const cd = (config && config.cash_discount_details) || {};
            const hasCashDetails = Object.values(cd).some(function(v) { return v && String(v).trim() !== ''; });
            if (hasCashDetails) {
                const cashTile = document.createElement('div');
                cashTile.className = 'pay-tile pay-tile-cash';
                cashTile.style.cssText = 'display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border:1px solid #e5e7eb;border-radius:14px;cursor:pointer;background:#fff;transition:border-color .15s,box-shadow .15s,background .15s;flex:1;min-width:180px;';
                cashTile.innerHTML = [
                    '<div>',
                    '  <p style="margin:0;font-size:14px;font-weight:600;color:#10213a;">Pay by Cash / Check</p>',
                    '  <p style="margin:3px 0 0;font-size:12px;color:#60708a;">No processing fee</p>',
                    '</div>',
                    `<div><p style="margin:0;font-size:17px;font-weight:600;color:#10213a;">${fmt(baseCents)}</p></div>`,
                ].join('');
                cashTile.addEventListener('click', () => selectCashOption(cashTile));
                cashTile.addEventListener('mouseenter', () => { if (!cashTile.classList.contains('pay-tile-selected')) { cashTile.style.borderColor = 'rgba(239,131,84,0.5)'; cashTile.style.transform = 'translateY(-1px)'; } });
                cashTile.addEventListener('mouseleave', () => { if (!cashTile.classList.contains('pay-tile-selected')) { cashTile.style.borderColor = '#e5e7eb'; cashTile.style.transform = ''; } });
                els.gatewayOptions.appendChild(cashTile);
            }

            // Disclosure text is set dynamically per tile in selectOption() / selectCashOption()

            // Auto-select first available gateway tile
            if (availableOptions.length > 0) {
                const firstTile = els.gatewayOptions.querySelector('.pay-tile:not(.pay-tile-cash)');
                if (firstTile) firstTile.click();
            } else {
                els.submitButton.disabled = true;
                els.submitStatus.textContent = 'Payment cannot be done because required bank details are missing from invoice custom fields.';
            }
        }

        function selectCashOption(tile) {
            // Always hide the gateway fee disclosure when cash is selected
            const discEl = document.getElementById('fee-disclosure-text');
            if (discEl) discEl.style.display = 'none';

            // Deselect all tiles
            document.querySelectorAll('.pay-tile').forEach((t) => {
                t.classList.remove('pay-tile-selected');
                t.style.borderColor = '#e5e7eb';
                t.style.boxShadow = '';
                t.style.background = '#fff';
                t.style.transform = '';
            });
            tile.classList.add('pay-tile-selected');
            tile.style.borderColor = 'rgba(239,131,84,0.8)';
            tile.style.boxShadow  = '0 0 0 3px rgba(239,131,84,0.18)';
            tile.style.background = 'rgba(255,245,239,0.96)';

            // Hide gateway card panel, show cash details
            els.cardPanel.classList.add('hidden');
            if (els.cashDetailsPanel) {
                const cd = (window.feeConfig && window.feeConfig.cash_discount_details) || {};
                const rows = [];
                if (cd.business_name) rows.push(`<strong>Make check payable to:</strong> ${cd.business_name}`);
                const addr = [cd.address, cd.city, [cd.state, cd.zip].filter(Boolean).join(' ')].filter(Boolean).join(', ');
                if (addr)         rows.push(`<strong>Mail to:</strong> ${addr}`);
                if (cd.phone)     rows.push(`<strong>Phone:</strong> ${cd.phone}`);
                if (cd.email)     rows.push(`<strong>Email:</strong> <a href="mailto:${cd.email}" style="color:#10213a;">${cd.email}</a>`);
                if (cd.instructions) rows.push(`<em style="color:#6b7c93;">${cd.instructions}</em>`);

                const detailRows = rows.length
                    ? rows.map((r) => `<div style="padding:4px 0;border-bottom:1px solid #f3f4f6;font-size:13px;">${r}</div>`).join('')
                    : '<p style="color:#6b7c93;font-size:13px;">Contact the merchant for offline payment details.</p>';
                const cdDisclosure = cd.disclosure
                    ? `<p style="margin:10px 0 0;font-size:12px;color:#6b7280;line-height:1.5;">${cd.disclosure}</p>`
                    : '';
                els.cashDetailsContent.innerHTML = detailRows + cdDisclosure;

                els.cashDetailsPanel.classList.remove('hidden');
            }

            state.selectedOption = null;
            state.cashSelected   = true;
            els.submitButton.disabled = false;
            els.submitButton.textContent = 'Submit payment';
            els.submitStatus.textContent = '';
        }

        function describeOption(option) {
            const paymentMethod = option.payment_method || option.hosted_fields.metadata.payment_method || 'CARD';

            if (option.hosted_fields.metadata.mode === 'direct') {
                return `${paymentMethod} sandbox payment`;
            }

            return `${paymentMethod} secure tokenization`;
        }

        function selectOption(option, tile) {
            state.selectedOption = option;

            // Deselect all tiles
            document.querySelectorAll('.pay-tile').forEach((t) => {
                t.classList.remove('pay-tile-selected');
                t.style.borderColor = '#e5e7eb';
                t.style.boxShadow = '';
                t.style.background = '#fff';
                t.style.transform = '';
            });
            if (tile) {
                tile.classList.add('pay-tile-selected');
                tile.style.borderColor = 'rgba(239,131,84,0.8)';
                tile.style.boxShadow  = '0 0 0 3px rgba(239,131,84,0.18)';
                tile.style.background = 'rgba(255,245,239,0.96)';
            }

            // Show matching disclosure text
            const disclosureEl = document.getElementById('fee-disclosure-text');
            if (disclosureEl) {
                const config = window.feeConfig || {};
                const gwKey2 = state.selectedOption && state.selectedOption.gateway
                    ? state.selectedOption.gateway.toLowerCase() : '';
                const text = config.fee_disclosure;
                disclosureEl.textContent = text || '';
                disclosureEl.style.display = text ? 'block' : 'none';
            }

            state.cashSelected = false;
            els.submitButton.textContent = 'Submit payment';

            // Hide cash panel, show card entry
            if (els.cashDetailsPanel) els.cashDetailsPanel.classList.add('hidden');
            resetToken('');
            setupCardEntry();
        }

        function loadScript(src) {
            return new Promise((resolve, reject) => {
                const existing = document.querySelector(`script[data-payment-script="${src}"]`);
                if (existing) {
                    resolve();
                    return;
                }

                const script = document.createElement('script');
                script.src = src;
                script.async = true;
                script.dataset.paymentScript = src;
                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            });
        }

        function describeMode(option) {
            const mode = option.hosted_fields.metadata.mode;

            if (mode === 'tokenizer') {
                return 'FluidPay secure tokenizer fields are ready. Card data stays inside FluidPay and only a short-lived token returns to us.';
            }

            const label = option.display_name || option.gateway.toUpperCase();

            if (mode === 'collectjs') {
                return `Gateway-hosted Collect.js fields are ready for ${label}.`;
            }

            if (mode === 'direct') {
                return `${label} will run a direct sandbox charge for the invoice amount when you confirm.`;
            }

            return `${label} is in test mode, so a gateway token can be entered directly.`;
        }

        function hideAllEntryModes() {
            els.hostedFields.classList.add('hidden');
            els.fluidpayPanel.classList.add('hidden');
            els.mockTokenPanel.classList.add('hidden');
            if (els.payaAchPanel) els.payaAchPanel.classList.add('hidden');
            //els.directPayPanel.classList.add('hidden');
            els.tokenInput.value = '';
            els.tokenizeButton.disabled = false;
        }

        function handleTokenizerResponse(resp) {
            if (!resp || typeof resp !== 'object') {
                els.submitButton.disabled = false;
                els.submitStatus.textContent = 'Unable to read the gateway response.';
                return;
            }

            switch (resp.status) {
                case 'success':
                    state.token = resp.token || '';
                    submitPayment();
                    return;
                case 'validation':
                    els.submitButton.disabled = false;
                    els.submitStatus.textContent = 'Please complete the highlighted gateway fields before continuing.';
                    return;
                default:
                    els.submitButton.disabled = false;
                    els.submitStatus.textContent = resp.msg || 'The gateway could not tokenize this card.';
            }
        }

        async function initializeFluidPay(option) {
            const fields = option.hosted_fields.fields || {};
            const metadata = option.hosted_fields.metadata || {};

            if (!fields.script_url || !metadata.public_key) {
                els.mockTokenPanel.classList.remove('hidden');
                return;
            }

            try {
                await loadScript(fields.script_url);
            } catch (error) {
                resetToken('Unable to load the FluidPay tokenizer library.');
                els.mockTokenPanel.classList.remove('hidden');
                return;
            }

            if (typeof window.Tokenizer !== 'function') {
                resetToken('FluidPay tokenizer is not available on this page.');
                els.mockTokenPanel.classList.remove('hidden');
                return;
            }

            els.fluidpayForm.innerHTML = '';
            state.fluidpayTokenizer = new window.Tokenizer({
                url: metadata.base_url,
                apikey: metadata.public_key,
                container: fields.container || '#fluidpay-payment-form',
                submission: handleTokenizerResponse,
                settings: {
                    payment: {
                        types: ['card'],
                        card: {
                            requireCVV: true,
                            mask_number: false,
                        },
                    },
                    styles: {
                        body: {
                            color: '#10213a',
                            'font-family': '"Space Grotesk", sans-serif',
                        },
                        input: {
                            border: '1px solid rgba(16, 33, 58, 0.12)',
                            'border-radius': '12px',
                            padding: '12px 14px',
                            'font-size': '16px',
                        },
                        'input:focus': {
                            border: '1px solid #ef8354',
                            outline: 'none',
                        },
                    },
                },
            });

            els.fluidpayPanel.classList.remove('hidden');
            els.submitButton.disabled = false;
            els.submitStatus.textContent = 'Enter your card details and click Submit payment.';
        }

        async function setupCardEntry() {
            els.cardPanel.classList.remove('hidden');
            if (!state.selectedOption) {
                return;
            }

            const option = state.selectedOption;
            const mode = option.hosted_fields.metadata.mode;
            hideAllEntryModes();
            //els.gatewayMode.textContent = describeMode(option);

            if (els.cardholderFields) {
                els.cardholderFields.classList.remove('hidden');
            }

            if (mode === 'tokenizer') {
                await initializeFluidPay(option);
                return;
            }

            if (mode === 'direct') {
                //els.directPayPanel.classList.remove('hidden');
                state.token = '__DIRECT_PAY__';
                els.submitButton.disabled = false;
                els.submitStatus.textContent = 'Ready to submit the invoice amount.';
                return;
            }

            if (mode === 'collectjs' && option.hosted_fields.fields.script_url) {
                try {
                    await loadScript(option.hosted_fields.fields.script_url);
                } catch (error) {
                    resetToken('Unable to load the hosted card library. Enter a gateway token to continue testing.');
                    els.mockTokenPanel.classList.remove('hidden');
                    return;
                }
            }

            if (mode === 'collectjs' && window.CollectJS) {
                els.hostedFields.classList.remove('hidden');
                return;
            }

            if (mode === 'paya_ach') {
                if (els.payaAchPanel) els.payaAchPanel.classList.remove('hidden');
                els.submitButton.disabled = true;
                els.submitStatus.textContent = 'Complete the bank details form above to continue.';
                loadPayaAccountForm();
                return;
            }

            els.mockTokenPanel.classList.remove('hidden');
        }

        els.tokenInput.addEventListener('input', (event) => {
            enableSubmit(event.target.value.trim());
        });

        els.tokenizeButton.addEventListener('click', () => {
            if (window.CollectJS && typeof window.CollectJS.startPaymentRequest === 'function') {
                window.CollectJS.startPaymentRequest();
            }
        });

        async function submitPayment() {
            els.submitButton.disabled = true;
            const isDirect = state.selectedOption.hosted_fields.metadata.mode === 'direct';
            els.submitStatus.textContent = isDirect
                ? `Submitting ${state.selectedOption.gateway.toUpperCase()} sandbox payment...`
                : 'Submitting payment...';

            const response = await fetch(`/api/v1/payment/sessions/${sessionToken}/submit`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({
                    token: state.token,
                    routing_rule_id: state.selectedOption.routing_rule_id,
                    payment_method: state.selectedOption.payment_method || state.selectedOption.hosted_fields.metadata.payment_method || 'CARD',
                    ...(state.payaBankToken ? { paya_bank_token: state.payaBankToken } : {}),
                    ...(state.cardholder ? state.cardholder : {}),
                }),
            });

            const payload = await response.json();

            if (!response.ok) {
                els.submitStatus.textContent = payload.message || 'Payment failed.';
                els.submitButton.disabled = false;
                const cancelUrl = state.details?.invoice?.cancel_redirect_url;
                if (cancelUrl) {
                    els.submitStatus.textContent += ' Redirecting you back...';
                    setTimeout(() => { window.location.href = cancelUrl; }, 3000);
                }
                return;
            }

            showConfirmation(payload);
        }

        els.submitButton.addEventListener('click', async () => {
            if (state.cashSelected) { showCashInstructions(); return; }
            if (!state.selectedOption) return;

            const mode = state.selectedOption.hosted_fields.metadata.mode;

            {
                const firstName = els.cardholderFirstName ? els.cardholderFirstName.value.trim() : '';
                const lastName  = els.cardholderLastName  ? els.cardholderLastName.value.trim()  : '';
                if (!firstName || !lastName) {
                    els.submitStatus.textContent = "Please enter the payer's first and last name.";
                    return;
                }

                const address1 = els.cardholderAddress1 ? els.cardholderAddress1.value.trim() : '';
                const city     = els.cardholderCity      ? els.cardholderCity.value.trim()      : '';
                const stateAbbr = els.cardholderState    ? els.cardholderState.value.trim()     : '';
                const zip      = els.cardholderZip       ? els.cardholderZip.value.trim()       : '';
                if (!address1 || !city || !stateAbbr || !zip) {
                    els.submitStatus.textContent = 'Please enter the full billing address.';
                    return;
                }

                state.cardholder = {
                    first_name: firstName,
                    last_name: lastName,
                    billing_address: {
                        address1: address1,
                        city:     city,
                        state:    stateAbbr,
                        zip:      zip,
                    },
                };
            }

            // Paya ACH: token from iframe postMessage OR tokenise custom form inputs
            if (mode === 'paya_ach') {
                if (state.payaBankToken) {
                    // Token already received from Paya AccountForm iframe
                    state.token = '__paya_ach__';
                    await submitPayment();
                    return;
                }

                // Custom form — read inputs and tokenise via our endpoint
                const routing = els.payaRouting ? els.payaRouting.value.trim() : '';
                const account = els.payaAccount ? els.payaAccount.value.trim() : '';
                if (!routing || routing.length !== 9) {
                    els.submitStatus.textContent = 'Please enter a valid 9-digit routing number.';
                    return;
                }
                if (!account || account.length < 4) {
                    els.submitStatus.textContent = 'Please enter a valid account number.';
                    return;
                }
                els.submitButton.disabled = true;
                els.submitStatus.textContent = 'Securing bank details…';
                try {
                    const tokenRes = await fetch(`/api/v1/payment/sessions/${sessionToken}/tokenize`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({
                            routing_number: routing,
                            account_number: account,
                            account_type: els.payaAccountType ? els.payaAccountType.value : 'checking',
                        }),
                    });
                    const tokenData = await tokenRes.json();
                    if (!tokenRes.ok) {
                        els.submitStatus.textContent = tokenData.message || 'Could not secure bank details. Please check your routing and account numbers.';
                        els.submitButton.disabled = false;
                        return;
                    }
                    state.payaBankToken = tokenData.token;
                    state.token = '__paya_ach__';
                    await submitPayment();
                } catch (e) {
                    els.submitStatus.textContent = 'Network error. Please try again.';
                    els.submitButton.disabled = false;
                }
                return;
            }

            if (mode === 'tokenizer') {
                if (!state.fluidpayTokenizer || typeof state.fluidpayTokenizer.submit !== 'function') {
                    els.submitStatus.textContent = 'FluidPay tokenizer is not ready yet.';
                    return;
                }
                els.submitButton.disabled = true;
                els.submitStatus.textContent = 'Securing card details with FluidPay...';
                state.fluidpayTokenizer.submit();
                return;
            }

            if (!state.token) return;
            await submitPayment();
        });

        loadDetails().catch(() => {
            els.title.textContent = 'Unable to load payment session';
            els.copy.textContent = 'Please contact support or request a new payment link.';
        });
    </script>
</x-payment::layouts.master>
