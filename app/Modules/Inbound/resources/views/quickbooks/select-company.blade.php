<x-inbound::layouts.master>
    <div class="shell">
        <section class="panel">
            <p class="eyebrow">QuickBooks Setup</p>
            <h2>Select a QuickBooks Company</h2>
            <p class="copy">Your Intuit account has access to multiple QuickBooks companies. Select the one you want to connect to this client.</p>

            @if ($errors->has('realm_id'))
                <div class="notice error">{{ $errors->first('realm_id') }}</div>
            @endif

            <form method="POST" action="{{ route('inbound.quickbooks.confirm-company') }}">
                @csrf
                <div style="display: grid; gap: 12px; margin-bottom: 20px;">
                    @foreach ($companies as $company)
                        <label style="display: flex; align-items: center; gap: 14px; padding: 16px 18px; border: 2px solid {{ $callbackRealm === $company['realmId'] ? '#132238' : 'rgba(19,34,56,0.12)' }}; border-radius: 16px; background: #fff; cursor: pointer;">
                            <input
                                type="radio"
                                name="realm_id"
                                value="{{ $company['realmId'] }}"
                                style="width: 18px; height: 18px; accent-color: #132238; flex-shrink: 0;"
                                {{ $callbackRealm === $company['realmId'] ? 'checked' : '' }}
                                required
                            >
                            <div>
                                <strong style="display: block; font-size: 1rem;">{{ $company['companyName'] }}</strong>
                                <span style="color: #6b7c93; font-size: 0.88rem;">Company ID: {{ $company['realmId'] }}</span>
                            </div>
                        </label>
                    @endforeach
                </div>

                <div class="actions">
                    <button type="submit" class="button primary">Connect Selected Company</button>
                    <a class="button secondary" href="{{ route('inbound.quickbooks.page', ['pms_client_id' => $pms_client_id]) }}">Cancel</a>
                </div>
            </form>
        </section>
    </div>
</x-inbound::layouts.master>
