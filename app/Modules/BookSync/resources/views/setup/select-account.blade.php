<x-booksync::layouts.master title="QuickBooks Setup">

<p class="eyebrow">BookSync Setup — Step 2 of 2</p>
<h1>Configure QuickBooks Defaults</h1>
<p class="copy">
    QuickBooks connected successfully for <strong>{{ $merchant->qb_company_name ?: $merchant->name }}</strong>.
    Choose the default accounts and customer that will be used when recording sales.
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
                        <option value="{{ $account['id'] }}" data-name="{{ $account['name'] }}">{{ $account['name'] }}</option>
                    @endforeach
                </select>
                <input type="hidden" id="deposit_account_name" name="deposit_account_name" value="">
            @else
                <div class="alert alert-warning" style="margin-top:8px;">No bank accounts found in QuickBooks. Add one first, then return to this link.</div>
            @endif
        </div>

        {{-- ── Default Item ─────────────────────────────────────────── --}}
        <div class="form-group">
            <label for="default_item_id">
                Default Income Item <span style="color:#c0392b;">*</span>
                <span style="font-weight:400;color:#6b7280;font-size:.82rem;margin-left:4px;">— credit side (income)</span>
            </label>
            <p style="font-size:.82rem;color:#9ca3af;margin:2px 0 8px;">The service or product item whose linked income account receives the credit on every Sales Receipt.</p>
            @if(count($items) > 0)
                <select id="default_item_id" name="default_item_id" required onchange="syncHidden('default_item_id','default_item_name',this)">
                    <option value="">— Select an item —</option>
                    @foreach($items as $item)
                        <option value="{{ $item['id'] }}" data-name="{{ $item['name'] }}">
                            {{ $item['name'] }}@if($item['type']) <span style="color:#9ca3af;"> ({{ $item['type'] }})</span>@endif
                        </option>
                    @endforeach
                </select>
                <input type="hidden" id="default_item_name" name="default_item_name" value="">
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
                        <option value="{{ $customer['id'] }}" data-name="{{ $customer['name'] }}">{{ $customer['name'] }}</option>
                    @endforeach
                </select>
                <input type="hidden" id="default_customer_name" name="default_customer_name" value="">
            @else
                <div class="alert alert-warning" style="margin-top:8px;">No active customers found. Create one in QuickBooks first (e.g. "Walk-in Customer"), then return to this link.</div>
            @endif
        </div>

        @if(count($accounts) > 0 && count($items) > 0 && count($customers) > 0)
        <div class="actions" style="margin-top:20px;">
            <button type="submit" class="button btn-primary">Save &amp; Finish Setup</button>
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
