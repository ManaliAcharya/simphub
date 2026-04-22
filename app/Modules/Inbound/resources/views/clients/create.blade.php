<x-inbound::layouts.master>
    <div class="shell">
        <section class="panel">
            <p class="eyebrow">Client Onboarding</p>
            <h1>Create PMS Client</h1>
            <p class="copy">This creates the client record and generates the unique PMS client id that will be used across webhook, API, and invoice flows.</p>

            <form method="POST" action="{{ route('inbound.clients.store') }}" class="form-grid">
                @csrf
                <label class="field">
                    <span>Client name</span>
                    <input type="text" name="client_name" value="{{ old('client_name') }}" required>
                </label>

                <label class="field">
                    <span>Client PMS</span>
                    <select name="client_pms" required>
                        <option value="CLIO" @selected(old('client_pms') === 'CLIO')>Clio</option>
                        <option value="LAWCUS" @selected(old('client_pms') === 'LAWCUS')>Lawcus</option>
                    </select>
                </label>

                <label class="checkbox">
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
                </label>

                <div class="actions">
                    <button class="button primary" type="submit">Create Client</button>
                    <a class="button secondary" href="{{ route('inbound.clio.page') }}">Back to Clio</a>
                </div>
            </form>

            @if ($errors->any())
                <div class="notice error">
                    {{ $errors->first() }}
                </div>
            @endif
        </section>
    </div>
</x-inbound::layouts.master>
