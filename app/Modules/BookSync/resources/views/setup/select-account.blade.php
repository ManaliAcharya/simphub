<x-booksync::layouts.master title="{{ isset($editing) ? 'Update Account Settings' : 'QuickBooks Setup' }}">

<p class="eyebrow">BookSync Setup{{ isset($editing) ? ' — Update Settings' : ' — Step 2 of 2' }}</p>
<h1>{{ isset($editing) ? 'Update Account Settings' : 'Configure QuickBooks Defaults' }}</h1>
<p class="copy">
    @if(isset($editing))
        Update the default accounts used when recording sales for <strong>{{ $merchant->qb_company_name ?: $merchant->name }}</strong>.
    @else
        QuickBooks connected successfully for <strong>{{ $merchant->qb_company_name ?: $merchant->name }}</strong>.
        Choose the default accounts and customer that will be used when recording sales.
    @endif
</p>

<div class="panel" style="max-width:520px;">
    <form method="POST" action="{{ route('booksync.setup.save-account', $setupToken) }}">
        @csrf

        {{-- ── Deposit Account ─────────────────────────────────────── --}}
        <div class="form-group">
            <label for="deposit_account_id">
                Default Deposit Account <span style="color:#c0392b;">*</span>
                <span style="font-weight:400;color:#6b7280;font-size:.82rem;margin-left:4px;">— debit side (bank)</span>
            </label>
            <p style="font-size:.82rem;color:#9ca3af;margin:2px 0 8px;">Where sales money lands in your bank. This is the debit entry on every Sales Receipt.</p>
            @if(count($accounts) > 0)
                <select id="deposit_account_id" name="deposit_account_id" required onchange="syncHidden('deposit_account_id','deposit_account_name',this)">
                    <option value="">— Select an account —</option>
                    @foreach($accounts as $account)
                        <option value="{{ $account['id'] }}" data-name="{{ $account['name'] }}"
                            {{ ($merchant->deposit_account_id ?? '') === $account['id'] ? 'selected' : '' }}>
                            {{ $account['name'] }}
                        </option>
                    @endforeach
                </select>
                <input type="hidden" id="deposit_account_name" name="deposit_account_name"
                    value="{{ $merchant->deposit_account_name ?? '' }}">
            @else
                <div class="alert alert-warning" style="margin-top:8px;">No bank accounts found in QuickBooks. Add one first, then return to this link.</div>
            @endif
        </div>

        {{-- ── Income Account ──────────────────────────────────────── --}}
        <div class="form-group">
            <label for="default_income_account_id">
                Income Account <span style="color:#c0392b;">*</span>
                <span style="font-weight:400;color:#6b7280;font-size:.82rem;margin-left:4px;">— credit side (income)</span>
            </label>
            <p style="font-size:.82rem;color:#9ca3af;margin:2px 0 8px;">The income account that gets credited on every sale (e.g. "Sales Income", "Service Revenue").</p>
            @if(count($incomeAccounts) > 0)
                <select id="default_income_account_id" name="default_income_account_id" required onchange="syncHidden('default_income_account_id','default_income_account_name',this)">
                    <option value="">— Select an income account —</option>
                    @foreach($incomeAccounts as $account)
                        <option value="{{ $account['id'] }}" data-name="{{ $account['name'] }}"
                            {{ ($merchant->default_income_account_id ?? '') === $account['id'] ? 'selected' : '' }}>
                            {{ $account['name'] }}@if($account['subtype']) &nbsp;({{ $account['subtype'] }})@endif
                        </option>
                    @endforeach
                </select>
                <input type="hidden" id="default_income_account_name" name="default_income_account_name"
                    value="{{ $merchant->default_income_account_name ?? '' }}">
            @else
                <div class="alert alert-warning" style="margin-top:8px;">No income accounts found in QuickBooks. Add one first, then return to this link.</div>
            @endif
        </div>

        {{-- ── Default Item ─────────────────────────────────────────── --}}
        <div class="form-group">
            <label for="default_item_id">
                Default Income Item <span style="color:#c0392b;">*</span>
                <span style="font-weight:400;color:#6b7280;font-size:.82rem;margin-left:4px;">— QB line item</span>
            </label>
            <p style="font-size:.82rem;color:#9ca3af;margin:2px 0 8px;">The service or product item used on every Sales Receipt line. Its linked income account should match the Income Account above.</p>
            @if(count($items) > 0)
                <select id="default_item_id" name="default_item_id" required onchange="syncHidden('default_item_id','default_item_name',this)">
                    <option value="">— Select an item —</option>
                    @foreach($items as $item)
                        <option value="{{ $item['id'] }}" data-name="{{ $item['name'] }}"
                            {{ ($merchant->default_item_id ?? '') === $item['id'] ? 'selected' : '' }}>
                            {{ $item['name'] }}@if($item['type']) &nbsp;({{ $item['type'] }})@endif
                        </option>
                    @endforeach
                </select>
                <input type="hidden" id="default_item_name" name="default_item_name"
                    value="{{ $merchant->default_item_name ?? '' }}">
            @else
                <div class="alert alert-warning" style="margin-top:8px;">No service or non-inventory items found. Create one in QuickBooks first, then return to this link.</div>
            @endif
        </div>

        {{-- ── Default Customer ─────────────────────────────────────── --}}
        <div class="form-group">
            <label for="default_customer_id">
                Default Customer <span style="color:#c0392b;">*</span>
                <span style="font-weight:400;color:#6b7280;font-size:.82rem;margin-left:4px;">— required by QuickBooks</span>
            </label>
            <p style="font-size:.82rem;color:#9ca3af;margin:2px 0 8px;">QuickBooks requires a customer on every Sales Receipt. All transactions will be recorded under this customer (e.g. "Walk-in Customer"). Individual customer names are preserved in the receipt description.</p>
            @if(count($customers) > 0)
                <select id="default_customer_id" name="default_customer_id" required onchange="syncHidden('default_customer_id','default_customer_name',this)">
                    <option value="">— Select a customer —</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer['id'] }}" data-name="{{ $customer['name'] }}"
                            {{ ($merchant->default_customer_id ?? '') === $customer['id'] ? 'selected' : '' }}>
                            {{ $customer['name'] }}
                        </option>
                    @endforeach
                </select>
                <input type="hidden" id="default_customer_name" name="default_customer_name"
                    value="{{ $merchant->default_customer_name ?? '' }}">
            @else
                <div class="alert alert-warning" style="margin-top:8px;">No active customers found. Create one in QuickBooks first (e.g. "Walk-in Customer"), then return to this link.</div>
            @endif
        </div>

        @if(count($accounts) > 0 && count($incomeAccounts) > 0 && count($items) > 0 && count($customers) > 0)
        <div class="actions" style="margin-top:20px;">
            <button type="submit" class="button btn-primary">
                {{ isset($editing) ? 'Save Changes' : 'Save & Finish Setup' }}
            </button>
            @if(isset($editing))
                <a href="{{ route('booksync.setup.complete', $setupToken) }}" class="button btn-secondary">Cancel</a>
            @endif
        </div>
        @endif
    </form>
</div>

<script>
function syncHidden(selectId, hiddenId, select) {
    const opt = select.options[select.selectedIndex];
    document.getElementById(hiddenId).value = opt.dataset.name || '';
}
</script>

</x-booksync::layouts.master>
