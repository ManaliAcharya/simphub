<x-inbound::layouts.master>
    <div class="shell">
        <section class="panel">
            <p class="eyebrow">Client Setup</p>
            <h2>{{ $heading }}</h2>
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
                <!-- <div class="summary-card">
                    <span>PMS client id</span>
                    <strong>{{ $client?->pms_client_id ?? '--' }}</strong>
                </div>
                <div class="summary-card">
                    <span>{{ $providerLabel }} webhook URL</span>
                    <strong>{{ $webhook_url ?? '--' }}</strong>
                </div> -->
                @if (! empty($organization_name) || ! empty($organization_id))
                    <div class="summary-card">
                        <span>Zoho organization</span>
                        <strong>{{ $organization_name ?? '--' }}</strong>
                        <span>{{ $organization_id ?? '--' }}</span>
                    </div>
                @endif
            </div>

            <div class="actions">
                <!-- <a class="button secondary" href="{{ route('inbound.clients.create') }}">Create New Client</a> -->
                @if ($connectUrl)
                    <a class="button primary" href="{{ $connectUrl }}">Connect {{ $providerLabel }}</a>
                @endif
            </div>

            @if ($shareUrl && ! $openedViaShareLink || ( empty($organization_name) ||  empty($organization_id)))
                <div class="summary-card" style="margin-top: 18px;">
                    <span>Share this setup link with client</span>
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 8px;">
                        <input id="setup-share-url" type="text" value="{{ $shareUrl }}" readonly style="flex: 1; min-width: 280px; padding: 10px 12px; border: 1px solid rgba(19, 34, 56, 0.12); border-radius: 12px; font: inherit;">
                        <button type="button" class="button secondary" onclick="copyShareLink()">Copy Link</button>
                    </div>
                </div>
                <script>
                    function copyShareLink() {
                        const input = document.getElementById('setup-share-url');
                        input.select();
                        input.setSelectionRange(0, 99999);
                        navigator.clipboard.writeText(input.value);
                    }
                </script>
            @endif

            @if ($provider === 'zoho' && $client && $connection)
                <div class="summary-card" style="margin-top: 18px;">
                    <span>Default Zoho deposit account</span>
                    <strong>{{ $client->zoho_default_account_name ?: 'Not selected' }}</strong>
                    <span>{{ $client->zoho_default_account_id ?: '--' }}</span>

                    @if (! empty($zoho_account_load_error))
                        <div class="notice error" style="margin-top: 8px;">{{ $zoho_account_load_error }}</div>
                    @endif

                    @if (! empty($zoho_payment_accounts))
                        <form method="POST" action="{{ route('inbound.zoho.default-account') }}" class="form-grid" style="margin-top: 10px;">
                            @csrf
                            <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
                            <label class="field">
                                <span>Default Zoho deposit account</span>
                                <select id="zoho-default-account-select" name="zoho_default_account_id" required>
                                    <option value="">Select account</option>
                                    @foreach ($zoho_payment_accounts as $account)
                                        <option
                                            value="{{ $account['account_id'] }}"
                                            @selected((string) $client->zoho_default_account_id === (string) $account['account_id'])
                                        >
                                            {{ $account['account_name'] }} ({{ $account['account_type'] ?: 'Account' }})
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                            <button type="submit" class="button primary">Save default account</button>
                        </form>
                    @else
                        <div class="notice error" style="margin-top: 8px;">
                            No Zoho accounts were returned for this organization. Reconnect Zoho or verify the connected user has access to chart of accounts.
                        </div>
                    @endif
                </div>
            @endif

            @if (!empty($webhook_instructions))
            <div class="webhook-card" style="margin-top: 18px; padding: 20px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; font-family: sans-serif;">
                <div style="display: flex; align-items: center; margin-bottom: 15px;">
                    <span style="font-size: 20px; margin-right: 8px;">🔗</span>
                    <strong style="font-size: 16px; color: #111827;">Zoho Books Webhook Setup Instructions</strong>
                </div>

                @if (!empty($webhook_instructions['demo_video_url']))
                    <div style="margin-bottom: 18px; padding: 14px 16px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px;">
                        <div style="font-weight: 600; color: #1d4ed8; margin-bottom: 6px;">Need a walkthrough?</div>
                        <a
                            href="{{ $webhook_instructions['demo_video_url'] }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            style="display: inline-block; padding: 10px 14px; background: #2563eb; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600;"
                        >
                            View webhook setup demo
                        </a>
                    </div>
                @endif

                <!-- Step-by-Step Guide -->
                <ol style="margin: 0 0 20px 20px; padding: 0; color: #374151; line-height: 1.6;">
                    @foreach ($webhook_instructions['steps'] as $index => $instruction)
                        <li style="margin-bottom: 10px;">{!! $instruction !!}</li>
                        
                        {{-- Inject the detail cards after Step 3 --}}
                        @if ($index === 2)
                            <div style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 15px; margin: 15px 0; font-size: 14px;">
                                <div style="margin-bottom: 8px;"><strong>Name:</strong> Invoice Create</div>
                                <div style="margin-bottom: 12px;"><strong>Module:</strong> Invoices</div>
                                
                                <!-- URL Field -->
                                <div style="margin-bottom: 12px;">
                                    <label style="font-weight: 600; display: block; margin-bottom: 4px;">URL to Notify:</label>
                                    <div style="display: flex; gap: 8px;">
                                        <input type="text" id="webhook-url-input" value="{{ $webhook_instructions['url'] }}" readonly style="flex: 1; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; background: #f3f4f6; color: #4b5563;">
                                        <button onclick="copyToClipboard('webhook-url-input', this)" style="padding: 0 12px; background: #2563eb; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: 600;">Copy</button>
                                    </div>
                                </div>

                                <!-- JSON Field -->
                                <div>
                                    <label style="font-weight: 600; display: block; margin-bottom: 4px;">Body (Choose Raw JSON and paste this):</label>
                                    <div style="position: relative;">
                                        <textarea id="webhook-json-input" rows="7" readonly style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 4px; background: #1f2937; color: #f9fafb; font-family: monospace; font-size: 13px; box-sizing: border-box;">{{ $webhook_instructions['json'] }}</textarea>
                                        <button onclick="copyToClipboard('webhook-json-input', this)" style="position: absolute; top: 8px; right: 8px; padding: 6px 10px; background: #4b5563; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">Copy JSON</button>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </ol>
            </div>

            <!-- Clipboard JS Script -->
            <script>
                function copyToClipboard(elementId, button) {
                    const copyText = document.getElementById(elementId);
                    copyText.select();
                    copyText.setSelectionRange(0, 99999); /* For mobile devices */
                    navigator.clipboard.writeText(copyText.value);

                    // Visual feedback
                    const originalText = button.innerText;
                    button.innerText = 'Copied! ✅';
                    button.style.background = '#059669';
                    
                    setTimeout(() => {
                        button.innerText = originalText;
                        button.style.background = elementId === 'webhook-json-input' ? '#4b5563' : '#2563eb';
                    }, 1500);
                }
            </script>
        @endif
        </section>

        <!-- <section class="panel list-panel">
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
        </section> -->
    </div>
</x-inbound::layouts.master>
