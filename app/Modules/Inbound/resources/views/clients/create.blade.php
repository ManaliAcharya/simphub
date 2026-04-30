<x-inbound::layouts.master>
    <div class="shell">
        <section class="panel">
            <p class="eyebrow">Client Onboarding</p>
            <h2>Create PMS Client</h2>
            <p class="copy">This creates the client record and generates the unique PMS client id that will be used across webhook, API, and invoice flows.</p>

            <form method="POST" action="{{ route('inbound.clients.store') }}" class="form-grid">
                @csrf
                <label class="field">
                    <span>Client name</span>
                    <input type="text" name="client_name" value="{{ old('client_name') }}" required>
                </label>

                <label class="field">
                    <span>Client PMS</span>
                    <select id="client-pms-select" name="client_pms" required>
                        <option value="CLIO" @selected(old('client_pms') === 'CLIO')>Clio</option>
                        <option value="ZOHO" @selected(old('client_pms') === 'ZOHO')>Zoho</option>
                        <!-- <option value="LAWCUS" @selected(old('client_pms') === 'LAWCUS')>Lawcus</option> -->
                    </select>
                </label>

                <label class="field" id="zoho-region-field" style="{{ old('client_pms') === 'ZOHO' ? '' : 'display:none;' }}">
                    <span>Zoho account region</span>
                    <select name="zoho_region">
                        <option value="">Select region</option>
                        @foreach ($zohoRegions as $code => $label)
                            <option value="{{ $code }}" @selected(old('zoho_region') === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <fieldset class="fieldset">
                    <legend>Allowed payment gateways</legend>
                    @if (! empty($availableGateways))
                        <div class="option-grid">
                            @foreach ($availableGateways as $gateway)
                                <label class="checkbox gateway-option">
                                    <input
                                        type="checkbox"
                                        name="allowed_payment_gateways[]"
                                        value="{{ $gateway }}"
                                        @checked(in_array($gateway, old('allowed_payment_gateways', []), true))
                                    >
                                    <span>
                                        <strong>{{ $gateway }}</strong>
                                        <!-- Available to the payer on the hosted payment page. -->
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @else
                        <p class="copy">No active routing rules were found yet. Add routing rules first to choose payment gateways for this client.</p>
                    @endif
                </fieldset>
                <input type="hidden" name="webhook_flow_enabled" value="1">
                <input type="hidden" name="call_api_to_pms" value="1">
                <input type="hidden" name="client_calls_our_api" value="0">
                <!-- <label class="checkbox">
                    <input type="hidden" name="webhook_flow_enabled" value="0">
                    <input type="checkbox" name="webhook_flow_enabled" value="1" @checked(old('webhook_flow_enabled'))>
                    <span>Webhook flow</span>
                </label>

                <label class="checkbox">
                    <input type="hidden" name="call_api_to_pms" value="0">
                    <input type="checkbox" name="call_api_to_pms" value="1" @checked(old('call_api_to_pms'))>
                    <span>Call API to PMS</span>
                </label>

                <label class="checkbox">
                    <input type="hidden" name="client_calls_our_api" value="0">
                    <input type="checkbox" name="client_calls_our_api" value="1" @checked(old('client_calls_our_api'))>
                    <span>Client will call our API</span>
                </label> -->

                <div class="actions">
                    <button class="button primary" type="submit">Create Client</button>
                    <!-- <a class="button secondary" href="{{ route('inbound.clio.page') }}">Back to Clio</a> -->
                </div>
            </form>

            <script>
                (function () {
                    const pmsSelect = document.getElementById('client-pms-select');
                    const zohoRegionField = document.getElementById('zoho-region-field');

                    function toggleZohoRegion() {
                        zohoRegionField.style.display = pmsSelect.value === 'ZOHO' ? '' : 'none';
                    }

                    pmsSelect.addEventListener('change', toggleZohoRegion);
                    toggleZohoRegion();
                }());
            </script>

            @if ($errors->any())
                <div class="notice error">
                    {{ $errors->first() }}
                </div>
            @endif
        </section>

        <!-- <section class="panel">
            <p class="eyebrow">Configured Clients</p>
            <h2>Existing PMS Clients</h2>

            @if ($clients->isEmpty())
                <p class="empty">No clients have been created yet.</p>
            @else
                <div class="client-list">
                    @foreach ($clients as $client)
                        <div class="client-row">
                            <span>Client</span>
                            <strong>{{ $client->client_name }}</strong>
                            <span>PMS</span>
                            <strong>{{ $client->client_pms }}</strong>
                            <span>PMS client id</span>
                            <code>{{ $client->pms_client_id }}</code>
                            <span>Allowed gateways</span>
                            <strong>{{ collect($client->allowed_payment_gateways)->implode(', ') ?: 'Not configured' }}</strong>
                        </div>
                    @endforeach
                </div>
            @endif
        </section> -->
    </div>
</x-inbound::layouts.master>
