{{-- Fills the Gateway Credentials FluidPay pickers (fluidpay-mid-select): MID Identifier with the
     MIDs the key can act for, then Processor ID with the chosen MID's processors — as the QB
     Multi-MID rows do. Uses the key typed on that tab, else the saved one; a blank IOLTA key
     falls back to the operating key, as at charge time. If FluidPay can't be reached, the
     picker can be swapped for a text box so the value can still be entered by hand. --}}
@once
<script>
    (function() {
        var selects = document.querySelectorAll('.csc-fp-lookup');
        if (!selects.length) return;

        var urls = {
            merchants: '{{ route('inbound.clients.fluidpay-merchants', $client->pms_client_id) }}',
            processors: '{{ route('inbound.clients.fluidpay-processors', $client->pms_client_id) }}',
        };
        var envSelect = document.getElementById('csc-gw-env');

        function post(url, el, body) {
            var csrf = el.form ? el.form.querySelector('input[name=_token]') : null;
            body.fund_type = el.dataset.fundType;
            body.environment = envSelect ? envSelect.value : null;
            var key = document.getElementById(el.dataset.apiInput);
            body.api_key = key && key.value.trim() ? key.value.trim() : null;

            return fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf ? csrf.value : '',
                },
                body: JSON.stringify(body),
            }).then(function(r) {
                return r.json();
            });
        }

        function addOption(select, value, text, selected) {
            var opt = document.createElement('option');
            opt.value = value;
            opt.textContent = text;
            opt.selected = !!selected;
            select.appendChild(opt);
        }

        function setStatus(el, message, isError) {
            var box = document.getElementById(el.dataset.status);
            if (!box) return;
            box.textContent = message || '';
            box.style.color = isError ? '#dc2626' : '#6b7c93';
            if (isError && el.tagName === 'SELECT') {
                var manual = document.createElement('button');
                manual.type = 'button';
                manual.className = 'button';
                manual.style.cssText = 'font-size:11px;padding:2px 8px;margin-left:6px;';
                manual.textContent = 'Enter manually';
                manual.addEventListener('click', function() {
                    switchToManual(el);
                });
                box.appendChild(manual);
            }
        }

        // Lookup failed — swap the dropdown for a text box with the same id/name/data, so saving,
        // "Test credentials" and the processor picker keep working.
        function switchToManual(select) {
            var input = document.createElement('input');
            input.type = 'text';
            input.id = select.id;
            input.name = select.name;
            input.value = select.value || select.dataset.current || '';
            input.autocomplete = 'off';
            input.style.cssText = select.style.cssText;
            Object.keys(select.dataset).forEach(function(k) {
                input.dataset[k] = select.dataset[k];
            });
            input.placeholder = select.dataset.lookup === 'merchants' ? 'FluidPay merchant ID' : 'FluidPay processor ID';
            select.parentNode.replaceChild(input, select);
            setStatus(input, '');
            input.focus();
        }

        // Keep an already-saved value selectable even if it's not in the list, so saving
        // again doesn't silently drop it.
        function render(select, items, placeholder, label) {
            var current = select.value || select.dataset.current || '';
            var found = false;

            select.innerHTML = '';
            addOption(select, '', placeholder, false);
            items.forEach(function(item) {
                if (item.id === current) found = true;
                addOption(select, item.id, label(item), item.id === current);
            });
            if (current && !found) {
                addOption(select, current, current + ' (saved — not on this key)', true);
            }
            return current;
        }

        function loadMerchants(select) {
            setStatus(select, 'Fetching MIDs from FluidPay…');
            post(urls.merchants, select, {})
                .then(function(data) {
                    if (!document.body.contains(select)) return;
                    if (!data.success) {
                        setStatus(select, data.message || 'Could not fetch MIDs from FluidPay.', true);
                        return;
                    }
                    var merchants = data.merchants || [];
                    var current = render(select, merchants, 'Select MID…', function(m) {
                        return m.id;
                    });
                    setStatus(select, merchants.length === 1 ? '1 MID found for this key.' :
                        merchants.length + ' MIDs found for this key.');

                    // One MID and nothing chosen yet: pick it, which loads its processors.
                    if (!current && merchants.length === 1) {
                        select.value = merchants[0].id;
                        select.dispatchEvent(new Event('change', {
                            bubbles: true
                        }));
                    }
                })
                .catch(function() {
                    setStatus(select, 'Could not fetch MIDs from FluidPay.', true);
                });
        }

        function loadProcessors(select) {
            var midInput = document.getElementById(select.dataset.midInput);
            var merchantId = midInput ? midInput.value.trim() : '';
            if (!merchantId) {
                setStatus(select, 'Select a MID first.');
                return;
            }

            setStatus(select, 'Fetching processors from FluidPay…');
            post(urls.processors, select, {
                    merchant_id: merchantId
                })
                .then(function(data) {
                    if (!document.body.contains(select)) return;
                    if (!data.success) {
                        setStatus(select, data.message || 'Could not fetch processors from FluidPay.', true);
                        return;
                    }
                    var processors = data.processors || [];
                    render(select, processors, 'Select processor…', function(p) {
                        return p.name + (p.status ? ' (' + p.status + ')' : '');
                    });
                    setStatus(select, processors.length === 1 ? '1 processor on this MID.' :
                        processors.length + ' processors on this MID.');
                })
                .catch(function() {
                    setStatus(select, 'Could not fetch processors from FluidPay.', true);
                });
        }

        // Looked up by id each time, since a picker may have been swapped for a text box.
        function each(lookup, fn) {
            document.querySelectorAll('.csc-fp-lookup[data-lookup=' + lookup + ']').forEach(function(el) {
                if (el.tagName === 'SELECT') fn(el);
            });
        }

        selects.forEach(function(select) {
            if (select.dataset.lookup !== 'merchants') return;
            var apiInput = document.getElementById(select.dataset.apiInput);
            if (!apiInput) return;
            var timer = null;
            apiInput.addEventListener('input', function() {
                clearTimeout(timer);
                var v = apiInput.value.trim();
                // Wait for something shaped like a full key (or a cleared field).
                if (v !== '' && (v.indexOf('api_') !== 0 || v.length < 12)) return;
                timer = setTimeout(function() {
                    var current = document.getElementById(select.id);
                    if (current && current.tagName === 'SELECT') loadMerchants(current);
                }, 600);
            });
        });

        if (envSelect) {
            envSelect.addEventListener('change', function() {
                each('merchants', loadMerchants);
            });
        }

        // Delegated, so it keeps working after a MID dropdown is swapped for a text box.
        function onMidChanged(e, delay) {
            if (!e.target.classList || !e.target.classList.contains('csc-fp-lookup')) return;
            if (e.target.dataset.lookup !== 'merchants') return;
            each('processors', function(select) {
                if (select.dataset.midInput !== e.target.id) return;
                clearTimeout(select._loadTimer);
                select._loadTimer = setTimeout(function() {
                    loadProcessors(select);
                }, delay);
            });
        }
        document.addEventListener('change', function(e) {
            onMidChanged(e, 0);
        });
        document.addEventListener('input', function(e) {
            if (e.target.tagName === 'INPUT') onMidChanged(e, 400);
        });

        each('merchants', loadMerchants);
        each('processors', function(select) {
            var midInput = document.getElementById(select.dataset.midInput);
            if (midInput && midInput.value.trim()) loadProcessors(select);
        });
    })();
</script>
@endonce
