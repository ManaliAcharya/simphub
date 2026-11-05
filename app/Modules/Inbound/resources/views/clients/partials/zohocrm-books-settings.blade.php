{{-- Zoho Books invoices for paid subscription periods (Zoho CRM clients).
     Expects $client, $connectUrl, $booksSettings, $booksOptions, $booksLoadError, $booksFailedCount. --}}
@php
    $books = $booksSettings ?? [];
    $orgs = $booksOptions['organizations'] ?? [];
    $accounts = $booksOptions['accounts'] ?? [];
    $items = $booksOptions['items'] ?? [];
@endphp
<div class="cc-card">
    <div class="cc-card-title">Zoho Books Invoices</div>
    <div class="cc-card-desc">
        When a subscriber pays a billing period, SimpHub creates the matching invoice in Zoho Books
        (same number as the SimpHub invoice, e.g. <code>SH-SUB-000123-2026-11</code>) and records the
        payment against it. The card fee is not included. The customer is matched by email, or added
        to Zoho Books if they are not there yet. Zoho Books does not email the customer.
    </div>

    @if ($booksLoadError)
        <div class="cc-notice error" role="alert" style="margin-bottom:12px;">
            SimpHub can't reach Zoho Books with this connection: {{ $booksLoadError }}
        </div>
        <div style="font-size:13px;color:#374151;margin-bottom:12px;">
            Connections made before Zoho Books invoices were available don't include Books access.
            Click <strong>Reconnect</strong> and approve the Zoho Books permissions, using a Zoho user
            who can access the organization's Zoho Books.
        </div>
        @if (! empty($connectUrl))
            <a href="{{ $connectUrl }}" class="button primary" style="font-size:13px;">Reconnect Zoho</a>
        @endif
    @else
        <form method="POST" action="{{ route('inbound.zohocrm.books-settings') }}">
            @csrf
            <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">

            <div class="cc-field">
                <label for="books-organization">Zoho Books organization</label>
                <select id="books-organization" name="organization_id" aria-describedby="books-organization-help">
                    <option value="">Choose an organization…</option>
                    @foreach ($orgs as $org)
                        <option value="{{ $org['organization_id'] }}" @selected((string) $books['organization_id'] === $org['organization_id'])>
                            {{ $org['name'] }} ({{ $org['organization_id'] }})
                        </option>
                    @endforeach
                </select>
                <div id="books-organization-help" style="font-size:12px;color:#6b7280;margin-top:4px;">
                    Changing the organization clears the deposit account and item. Save, then choose them again.
                </div>
            </div>

            @if ($books['organization_id'])
                <div class="cc-field">
                    <label for="books-account">Deposit payments to</label>
                    <select id="books-account" name="account_id">
                        <option value="">Zoho Books default deposit account</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account['account_id'] }}" @selected((string) $books['account_id'] === $account['account_id'])>
                                {{ $account['account_name'] }}{{ $account['account_type'] ? ' (' . $account['account_type'] . ')' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="cc-field">
                    <label for="books-item">Invoice line item</label>
                    <select id="books-item" name="item_id" aria-describedby="books-item-help">
                        <option value="">No item: a line named “Subscription – Month Year”</option>
                        @foreach ($items as $item)
                            <option value="{{ $item['item_id'] }}" @selected((string) $books['item_id'] === $item['item_id'])>
                                {{ $item['name'] }}
                            </option>
                        @endforeach
                    </select>
                    <div id="books-item-help" style="font-size:12px;color:#6b7280;margin-top:4px;">
                        The item sets the income account in Zoho Books. The amount is always the subscription amount for that period.
                    </div>
                </div>
            @endif

            <div class="cc-field" style="display:flex;align-items:center;gap:8px;">
                <input type="hidden" name="enabled" value="0">
                <input type="checkbox" id="books-enabled" name="enabled" value="1" @checked($books['enabled'])
                    @disabled(! $books['organization_id'])>
                <label for="books-enabled" style="margin:0;">Create Zoho Books invoices for paid subscription periods</label>
            </div>
            @if (! $books['organization_id'])
                <div style="font-size:12px;color:#92400e;margin:-4px 0 12px;">Choose and save an organization first.</div>
            @endif

            <button type="submit" class="button primary" style="font-size:13px;">Save Zoho Books settings</button>
        </form>
    @endif

    @if (($booksFailedCount ?? 0) > 0)
        <div class="cc-notice error" role="alert" style="margin-top:16px;">
            {{ $booksFailedCount }} paid {{ $booksFailedCount === 1 ? 'invoice is' : 'invoices are' }} not in Zoho Books
            yet because the last try failed. SimpHub retries automatically; you can also retry now.
        </div>
        <form method="POST" action="{{ route('inbound.zohocrm.books-retry') }}" style="margin-top:8px;">
            @csrf
            <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
            <button type="submit" class="button" style="font-size:13px;">Retry failed invoices</button>
        </form>
    @endif
</div>
