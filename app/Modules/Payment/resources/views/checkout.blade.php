<x-payment::layouts.master>
    <div class="shell">
        <section class="panel hero">
            <p class="eyebrow">Secure Invoice Payment</p>
            <h1 id="invoice-title">Loading invoice...</h1>
            <p id="invoice-copy">We are preparing your secure payment session.</p>
            <div class="summary">
                <div>
                    <span>Amount</span>
                    <strong id="invoice-amount">--</strong>
                </div>
                <div>
                    <span>Fund type</span>
                    <strong id="invoice-fund-type">--</strong>
                </div>
                <div>
                    <span>Status</span>
                    <strong id="session-status">--</strong>
                </div>
            </div>
        </section>

        <section class="panel checkout">
            <div class="checkout-header">
                <div>
                    <p class="step">Step 1</p>
                    <h2>Choose how you want to pay</h2>
                </div>
                <button id="pay-card-button" class="primary-button" type="button">Pay by card</button>
            </div>

            <div id="card-panel" class="card-panel hidden">
                <p class="step">Step 2</p>
                <h3>Enter card details in the secure gateway fields</h3>
                <p id="gateway-mode" class="muted"></p>

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

                <div id="mock-token-panel" class="mock-panel hidden">
                    <label for="token-input">Gateway token</label>
                    <input id="token-input" type="text" placeholder="tok_demo_123" />
                    <p class="muted">Collect.js is not configured in this environment, so a gateway token can be entered directly for testing.</p>
                </div>

                <div class="actions">
                    <button id="submit-button" class="primary-button" type="button" disabled>Submit payment</button>
                    <span id="submit-status" class="muted"></span>
                </div>
            </div>
        </section>
    </div>

    <script>
        window.paymentSessionToken = @json($sessionToken);
    </script>
    <script>
        const sessionToken = window.paymentSessionToken;
        const state = { details: null, token: '' };
        const els = {
            title: document.getElementById('invoice-title'),
            copy: document.getElementById('invoice-copy'),
            amount: document.getElementById('invoice-amount'),
            fundType: document.getElementById('invoice-fund-type'),
            status: document.getElementById('session-status'),
            payCardButton: document.getElementById('pay-card-button'),
            cardPanel: document.getElementById('card-panel'),
            hostedFields: document.getElementById('hosted-fields'),
            mockTokenPanel: document.getElementById('mock-token-panel'),
            tokenInput: document.getElementById('token-input'),
            submitButton: document.getElementById('submit-button'),
            submitStatus: document.getElementById('submit-status'),
            gatewayMode: document.getElementById('gateway-mode'),
            tokenizeButton: document.getElementById('tokenize-button'),
        };

        async function loadDetails() {
            const response = await fetch(`/api/v1/payment/sessions/${sessionToken}`);
            const details = await response.json();
            state.details = details;
            const amount = (details.invoice.amount_cents / 100).toFixed(2);
            els.title.textContent = `Invoice ${details.invoice.external_invoice_id}`;
            els.copy.textContent = 'Review the invoice details below and continue to the secure card form.';
            els.amount.textContent = `${amount} ${details.invoice.currency}`;
            els.fundType.textContent = details.invoice.fund_type;
            els.status.textContent = details.session.status;
            els.gatewayMode.textContent = details.hosted_fields.metadata.mode === 'collectjs'
                ? 'Gateway-hosted Collect.js fields are ready.'
                : 'Gateway hosted fields are not configured, so test mode is enabled.';
        }

        function enableSubmit(token) {
            state.token = token;
            els.submitButton.disabled = !token;
            els.submitStatus.textContent = token ? 'Gateway token ready.' : '';
        }

        function loadScript(src) {
            return new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = src;
                script.async = true;
                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            });
        }

        async function setupCardEntry() {
            els.cardPanel.classList.remove('hidden');
            if (!state.details) return;
            const mode = state.details.hosted_fields.metadata.mode;

            if (mode === 'collectjs' && state.details.hosted_fields.fields.script_url) {
                try {
                    await loadScript(state.details.hosted_fields.fields.script_url);
                } catch (error) {
                }
            }

            if (mode === 'collectjs' && window.CollectJS) {
                els.hostedFields.classList.remove('hidden');
                return;
            }

            els.mockTokenPanel.classList.remove('hidden');
        }

        els.payCardButton.addEventListener('click', () => {
            setupCardEntry();
        });

        els.tokenInput.addEventListener('input', (event) => {
            enableSubmit(event.target.value.trim());
        });

        els.tokenizeButton.addEventListener('click', () => {
            if (window.CollectJS && typeof window.CollectJS.startPaymentRequest === 'function') {
                window.CollectJS.startPaymentRequest();
            }
        });

        els.submitButton.addEventListener('click', async () => {
            if (!state.token) return;
            els.submitButton.disabled = true;
            els.submitStatus.textContent = 'Submitting payment...';

            const response = await fetch(`/api/v1/payment/sessions/${sessionToken}/submit`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({
                    token: state.token,
                    payment_method: 'CARD',
                }),
            });

            const payload = await response.json();

            if (!response.ok) {
                els.submitStatus.textContent = payload.message || 'Payment failed.';
                els.submitButton.disabled = false;
                return;
            }

            els.submitStatus.textContent = `Payment approved. Gateway reference: ${payload.gateway_txn_id}`;
            els.status.textContent = 'COMPLETED';
        });

        loadDetails().catch(() => {
            els.title.textContent = 'Unable to load payment session';
            els.copy.textContent = 'Please contact support or request a new payment link.';
        });
    </script>
</x-payment::layouts.master>
