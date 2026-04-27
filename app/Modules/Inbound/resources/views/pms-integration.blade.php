<x-inbound::layouts.master>
    <div class="shell">
        <section class="panel">
            <p class="eyebrow">Client Setup</p>
            <h1>{{ $heading }}</h1>
            <p class="copy">{{ $copy }}</p>

            @if ($success)
                <div class="notice success">{{ $success }}</div>
            @endif

            @if ($error)
                <div class="notice error">{{ $error }}</div>
            @endif

            <div class="summary-grid">
                <div class="summary-card">
                    <span>Selected client</span>
                    <strong>{{ $client?->client_name ?? 'No client selected' }}</strong>
                </div>
                <div class="summary-card">
                    <span>PMS client id</span>
                    <strong>{{ $client?->pms_client_id ?? '--' }}</strong>
                </div>
                <div class="summary-card">
                    <span>{{ $providerLabel }} webhook URL</span>
                    <strong>{{ $webhook_url ?? '--' }}</strong>
                </div>
                @if (! empty($organization_name) || ! empty($organization_id))
                    <div class="summary-card">
                        <span>Zoho organization</span>
                        <strong>{{ $organization_name ?? '--' }}</strong>
                        <span>{{ $organization_id ?? '--' }}</span>
                    </div>
                @endif
            </div>

            <div class="actions">
                <a class="button secondary" href="{{ route('inbound.clients.create') }}">Create Client</a>
                @if ($connectUrl)
                    <a class="button primary" href="{{ $connectUrl }}">Connect {{ $providerLabel }}</a>
                @endif
            </div>

            @if (! empty($webhook_instructions))
                <div class="notice success" style="margin-top: 18px;">
                    <strong>Webhook setup instructions</strong>
                    <ul style="margin: 10px 0 0 18px; padding: 0;">
                        @foreach ($webhook_instructions as $instruction)
                            <li style="margin-bottom: 8px;">{{ $instruction }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

        <section class="panel list-panel">
            <p class="eyebrow">Configured {{ $providerLabel }} Clients</p>
            <div class="client-list">
                @forelse ($clients as $configuredClient)
                    <a class="client-row" href="{{ route("inbound.{$provider}.page", ['pms_client_id' => $configuredClient->pms_client_id]) }}">
                        <strong>{{ $configuredClient->client_name }}</strong>
                        <span>{{ $configuredClient->client_pms }}</span>
                        <code>{{ $configuredClient->pms_client_id }}</code>
                    </a>
                @empty
                    <p class="empty">No {{ $providerLabel }} clients configured yet.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-inbound::layouts.master>
