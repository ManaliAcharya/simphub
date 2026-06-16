<x-booksync::layouts.master title="Select Deposit Account">

<p class="eyebrow">BookSync Setup — Step 2 of 2</p>
<h1>Select Deposit Account</h1>
<p class="copy">
    QuickBooks connected successfully for <strong>{{ $merchant->qb_company_name ?: $merchant->name }}</strong>.
    Now select which bank account your sales transactions should deposit into.
</p>

<div class="panel" style="max-width:480px;">
    <form method="POST" action="{{ route('booksync.setup.save-account', $setupToken) }}">
        @csrf
        <div class="form-group">
            <label for="deposit_account_id">Deposit Account <span style="color:#c0392b;">*</span></label>
            @if(count($accounts) > 0)
                <select id="deposit_account_id" name="deposit_account_id" required onchange="syncAccountName(this)">
                    <option value="">— Select an account —</option>
                    @foreach($accounts as $account)
                        <option value="{{ $account['id'] }}" data-name="{{ $account['name'] }}">
                            {{ $account['name'] }}
                        </option>
                    @endforeach
                </select>
                <input type="hidden" id="deposit_account_name" name="deposit_account_name" value="">
            @else
                <div class="alert alert-warning" style="margin-top:8px;">No bank or current asset accounts found in QuickBooks. Please add an account first, then return to this link.</div>
            @endif
        </div>

        @if(count($accounts) > 0)
        <div class="actions" style="margin-top:20px;">
            <button type="submit" class="button btn-primary">Save &amp; Finish Setup</button>
        </div>
        @endif
    </form>
</div>

<script>
function syncAccountName(select) {
    const opt = select.options[select.selectedIndex];
    document.getElementById('deposit_account_name').value = opt.dataset.name || '';
}
</script>

</x-booksync::layouts.master>
