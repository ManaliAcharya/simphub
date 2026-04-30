<x-payment::layouts.master>
    <div class="shell">
        <section class="panel hero">
            <p class="eyebrow">Secure Invoice Payment</p>
            <h2 id="invoice-title">Loading invoice...</h2>
            <p id="invoice-copy">We are preparing your secure payment session.</p>
            <div class="summary">
                <div>
                    <span>Amount</span>
                    <strong id="invoice-amount">--</strong>
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

                <div id="fluidpay-panel" class="hidden">
                    <div class="field">
                        <label>Card details</label>
                        <div id="fluidpay-payment-form"></div>
                    </div>
                    <div class="actions">
                        <button id="fluidpay-tokenize-button" class="primary-button" type="button">Securely tokenize card with FluidPay</button>
                    </div>
                </div>

                <div id="mock-token-panel" class="mock-panel hidden">
                    <label for="token-input">Gateway token</label>
                    <input id="token-input" type="text" placeholder="tok_demo_123" />
                    <p class="muted">A hosted tokenizer is not configured in this environment, so a gateway token can be entered directly for testing.</p>
                </div>

                <div id="direct-pay-panel" class="mock-panel hidden">
                    <p class="muted">This gateway charges the invoice amount directly in sandbox mode without collecting form fields on this page.</p>
                    <div class="actions">
                        <button id="direct-pay-button" class="primary-button" type="button">Pay invoice amount now</button>
                    </div>
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
        const state = {
            details: null,
            token: '',
            selectedOption: null,
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
            hostedFields: document.getElementById('hosted-fields'),
            fluidpayPanel: document.getElementById('fluidpay-panel'),
            fluidpayForm: document.getElementById('fluidpay-payment-form'),
            fluidpayTokenizeButton: document.getElementById('fluidpay-tokenize-button'),
            mockTokenPanel: document.getElementById('mock-token-panel'),
            directPayPanel: document.getElementById('direct-pay-panel'),
            directPayButton: document.getElementById('direct-pay-button'),
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
            Array.from(els.gatewayOptions.children).forEach((child) => child.classList.remove('is-selected'));
            if (button) {
                button.classList.add('is-selected');
            }

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

            if (mode === 'collectjs') {
                return `Gateway-hosted Collect.js fields are ready for ${option.gateway.toUpperCase()}.`;
            }

            if (mode === 'direct') {
                return `${option.gateway.toUpperCase()} will run a direct sandbox charge for the invoice amount when you confirm.`;
            }

            return `${option.gateway.toUpperCase()} is in test mode, so a gateway token can be entered directly.`;
        }

        function hideAllEntryModes() {
            els.hostedFields.classList.add('hidden');
            els.fluidpayPanel.classList.add('hidden');
            els.mockTokenPanel.classList.add('hidden');
            els.directPayPanel.classList.add('hidden');
            els.tokenInput.value = '';
            els.tokenizeButton.disabled = false;
            els.fluidpayTokenizeButton.disabled = false;
            els.directPayButton.disabled = false;
        }

        function handleTokenizerResponse(resp) {
            if (!resp || typeof resp !== 'object') {
                resetToken('Unable to read the gateway response.');
                return;
            }

            switch (resp.status) {
                case 'success':
                    enableSubmit(resp.token || '');
                    return;
                case 'validation':
                    resetToken('Please complete the highlighted gateway fields before continuing.');
                    return;
                default:
                    resetToken(resp.msg || 'The gateway could not tokenize this card.');
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
                            mask_number: true,
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

            els.fluidpayTokenizeButton.textContent = fields.button_label || 'Securely tokenize card';
            els.fluidpayPanel.classList.remove('hidden');
        }

        async function setupCardEntry() {
            els.cardPanel.classList.remove('hidden');
            if (!state.selectedOption) {
                return;
            }

            const option = state.selectedOption;
            const mode = option.hosted_fields.metadata.mode;
            hideAllEntryModes();
            els.gatewayMode.textContent = describeMode(option);

            if (mode === 'tokenizer') {
                await initializeFluidPay(option);
                return;
            }

            if (mode === 'direct') {
                els.directPayPanel.classList.remove('hidden');
                els.submitButton.disabled = true;
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

        els.fluidpayTokenizeButton.addEventListener('click', () => {
            if (!state.fluidpayTokenizer || typeof state.fluidpayTokenizer.submit !== 'function') {
                resetToken('FluidPay tokenizer is not ready yet.');
                return;
            }

            resetToken('Requesting a secure payment token from FluidPay...');
            els.fluidpayTokenizeButton.disabled = true;
            state.fluidpayTokenizer.submit();
            window.setTimeout(() => {
                els.fluidpayTokenizeButton.disabled = false;
            }, 1200);
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

        els.directPayButton.addEventListener('click', async () => {
            if (!state.selectedOption) {
                return;
            }

            els.directPayButton.disabled = true;
            els.submitStatus.textContent = `Submitting ${state.selectedOption.gateway.toUpperCase()} sandbox payment...`;

            const response = await fetch(`/api/v1/payment/sessions/${sessionToken}/submit`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({
                    token: '__DIRECT_PAY__',
                    routing_rule_id: state.selectedOption.routing_rule_id,
                    payment_method: state.selectedOption.payment_method || state.selectedOption.hosted_fields.metadata.payment_method || 'ACH',
                }),
            });

            const payload = await response.json();

            if (!response.ok) {
                els.submitStatus.textContent = payload.message || 'Payment failed.';
                els.status.textContent = 'FAILED';
                els.directPayButton.disabled = false;
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
