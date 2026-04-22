<x-inbound::layouts.master>
    <div class="shell">
        <section class="panel">
            <p class="eyebrow">Client Setup</p>
            <h1>Connect Clio for a configured client</h1>
            <p class="copy">Create a client first, then connect that client to Clio so the generated PMS client id follows the webhook and invoice flow.</p>

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
                    <span>Webhook URL</span>
                    <strong>{{ $connection?->webhook_url ?? $webhookUrl }}</strong>
                </div>
            </div>

            <div class="actions">
                <a class="button secondary" href="{{ route('inbound.clients.create') }}">Create Client</a>
                @if ($connectUrl)
                    <a class="button primary" href="{{ $connectUrl }}">Connect Clio</a>
                @endif
            </div>
        </section>

        <section class="panel list-panel">
            <p class="eyebrow">Configured Clients</p>
            <div class="client-list">
                @forelse ($clients as $configuredClient)
                    <a class="client-row" href="{{ route('inbound.clio.page', ['pms_client_id' => $configuredClient->pms_client_id]) }}">
                        <strong>{{ $configuredClient->client_name }}</strong>
                        <span>{{ $configuredClient->client_pms }}</span>
                        <code>{{ $configuredClient->pms_client_id }}</code>
                    </a>
                @empty
                    <p class="empty">No clients configured yet.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-inbound::layouts.master>
