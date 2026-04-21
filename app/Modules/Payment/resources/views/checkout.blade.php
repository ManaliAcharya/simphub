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
                <div id="gateway-options" class="gateway-options"></div>
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
        const state = { details: null, token: '', selectedOption: null };
        const els = {
            title: document.getElementById('invoice-title'),
            copy: document.getElementById('invoice-copy'),
            amount: document.getElementById('invoice-amount'),
            fundType: document.getElementById('invoice-fund-type'),
            status: document.getElementById('session-status'),
            gatewayOptions: document.getElementById('gateway-options'),
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
            renderOptions(details.payment_options || []);
        }

        function enableSubmit(token) {
            state.token = token;
            els.submitButton.disabled = !token || !state.selectedOption;
            els.submitStatus.textContent = token ? 'Gateway token ready.' : '';
        }

        function renderOptions(options) {
            els.gatewayOptions.innerHTML = '';

            options.forEach((option, index) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'primary-button';
                button.textContent = `Pay with ${option.gateway.toUpperCase()}`;
                button.addEventListener('click', () => selectOption(option, button));
                els.gatewayOptions.appendChild(button);

                if (index === 0) {
                    selectOption(option, button);
                }
            });
        }

        function selectOption(option, button) {
            state.selectedOption = option;
            Array.from(els.gatewayOptions.children).forEach((child) => {
                child.style.outline = '';
            });
            if (button) {
                button.style.outline = '3px solid rgba(239, 131, 84, 0.4)';
            }

            els.gatewayMode.textContent = option.hosted_fields.metadata.mode === 'collectjs'
                ? `Gateway-hosted Collect.js fields are ready for ${option.gateway.toUpperCase()}.`
                : `${option.gateway.toUpperCase()} is in test mode, so a gateway token can be entered directly.`;

            setupCardEntry();
            enableSubmit(state.token);
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
            if (!state.selectedOption) return;
            const mode = state.selectedOption.hosted_fields.metadata.mode;
            els.hostedFields.classList.add('hidden');
            els.mockTokenPanel.classList.add('hidden');

            if (mode === 'collectjs' && state.selectedOption.hosted_fields.fields.script_url) {
                try {
                    await loadScript(state.selectedOption.hosted_fields.fields.script_url);
                } catch (error) {
                }
            }

            if (mode === 'collectjs' && window.CollectJS) {
                els.hostedFields.classList.remove('hidden');
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
                    routing_rule_id: state.selectedOption.routing_rule_id,
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
