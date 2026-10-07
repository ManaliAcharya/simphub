{{-- "Test credentials" for FluidPay keys — shared by Gateway Credentials and Multi-MID rows.
     A button opts in with class="fluidpay-test-creds" and data attributes naming its inputs:
       data-test-url        endpoint (inbound.clients.fluidpay-test-credentials)
       data-api-input       id of the private key input
       data-public-input    id of the public key input
       data-result          id of the element that shows the results
       data-mid-input       (Multi-MID) id of the MID Identifier input
       data-route-type      (Multi-MID) fees_on | fees_off
       data-env-input       (Gateway Credentials) id of the Environment select
     The server checks the private key and processor access (blank fields fall back to the
     saved keys); the public key is then checked here by loading FluidPay's card form with it.
     Nothing is saved. --}}
@once
<script>
    (function() {
        var tokenizerScripts = {};

        var styles = {
            pass: { icon: '✓', color: '#065f46', bg: '#ecfdf5', border: '#a7f3d0' },
            warn: { icon: '!', color: '#92400e', bg: '#fffbeb', border: '#fde68a' },
            fail: { icon: '✕', color: '#991b1b', bg: '#fef2f2', border: '#fecaca' },
            info: { icon: '…', color: '#374151', bg: '#f9fafb', border: '#e5e7eb' },
        };

        function row(check) {
            var s = styles[check.status] || styles.info;
            var el = document.createElement('div');
            el.style.cssText = 'display:flex;gap:8px;align-items:flex-start;padding:7px 10px;margin-top:6px;' +
                'border-radius:8px;font-size:12px;line-height:1.45;background:' + s.bg +
                ';border:1px solid ' + s.border + ';color:' + s.color + ';';
            var icon = document.createElement('strong');
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = s.icon;
            var text = document.createElement('span');
            var label = document.createElement('strong');
            label.textContent = check.label + ': ';
            text.appendChild(label);
            text.appendChild(document.createTextNode(check.message));
            el.appendChild(icon);
            el.appendChild(text);
            return el;
        }

        function loadScript(src) {
            if (!tokenizerScripts[src]) {
                tokenizerScripts[src] = new Promise(function(resolve, reject) {
                    var script = document.createElement('script');
                    script.src = src;
                    script.onload = resolve;
                    script.onerror = reject;
                    document.head.appendChild(script);
                });
            }
            return tokenizerScripts[src];
        }

        // FluidPay has no API to validate a public key, so load the real card form
        // with it (off-screen) and report whether it comes up.
        function testPublicKey(tokenizer, box, key) {
            var pending = row({ status: 'info', label: 'Public key', message: 'Loading FluidPay card form with this key…' });
            box.appendChild(pending);

            var holderId = 'cred-test-tokenizer-' + key;
            var old = document.getElementById(holderId);
            if (old) old.remove();
            var holder = document.createElement('div');
            holder.id = holderId;
            holder.setAttribute('aria-hidden', 'true');
            holder.style.cssText = 'position:absolute;left:-10000px;top:0;width:400px;height:300px;';
            document.body.appendChild(holder);

            var done = false;
            function finish(check) {
                if (done) return;
                done = true;
                box.replaceChild(row(check), pending);
                holder.remove();
            }

            loadScript(tokenizer.script_url).then(function() {
                if (typeof window.Tokenizer !== 'function') {
                    finish({ status: 'fail', label: 'Public key', message: 'FluidPay card form script loaded but is not usable on this page.' });
                    return;
                }
                new window.Tokenizer({
                    url: tokenizer.base_url,
                    apikey: tokenizer.public_key,
                    container: '#' + holderId,
                    submission: function() {},
                    onLoad: function() {
                        finish({ status: 'pass', label: 'Public key', message: 'FluidPay card form loaded with this key. Confirm on a real payment link that the card fields accept input.' });
                    },
                });
                setTimeout(function() {
                    finish({ status: 'fail', label: 'Public key', message: 'FluidPay card form did not load with this key within 15 seconds — the key is likely wrong, from the other environment, or URL-restricted in FluidPay.' });
                }, 15000);
            }).catch(function() {
                finish({ status: 'fail', label: 'Public key', message: 'Could not load FluidPay\'s card form script.' });
            });
        }

        document.addEventListener('click', function(event) {
            var button = event.target.closest('.fluidpay-test-creds');
            if (!button) return;

            var d = button.dataset;
            var box = document.getElementById(d.result);
            var val = function(id) {
                var el = id ? document.getElementById(id) : null;
                return el ? el.value.trim() : '';
            };
            var form = button.closest('form');
            var csrf = form ? form.querySelector('input[name=_token]') : null;

            box.innerHTML = '';
            box.style.display = 'block';
            box.appendChild(row({ status: 'info', label: 'Testing', message: 'Checking with FluidPay…' }));
            button.disabled = true;

            fetch(d.testUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf ? csrf.value : '',
                    },
                    body: JSON.stringify({
                        route_type: d.routeType || null,
                        mid_identifier: val(d.midInput),
                        environment: val(d.envInput) || null,
                        api_key: val(d.apiInput),
                        public_key: val(d.publicInput),
                    }),
                })
                .then(function(r) {
                    return r.json().then(function(data) {
                        if (!r.ok) throw new Error(data.message || ('HTTP ' + r.status));
                        return data;
                    });
                })
                .then(function(data) {
                    box.innerHTML = '';
                    (data.checks || []).forEach(function(check) {
                        box.appendChild(row(check));
                    });
                    if (data.tokenizer) testPublicKey(data.tokenizer, box, d.result);
                })
                .catch(function(err) {
                    box.innerHTML = '';
                    box.appendChild(row({ status: 'fail', label: 'Test failed', message: err.message }));
                })
                .finally(function() {
                    button.disabled = false;
                });
        });
    })();
</script>
@endonce
