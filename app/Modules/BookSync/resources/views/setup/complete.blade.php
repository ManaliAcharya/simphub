<x-booksync::layouts.master title="Setup Complete">

<p class="eyebrow">BookSync Setup — Complete</p>
<h1>You're all set!</h1>
<p class="copy">
    QuickBooks has been connected for <strong>{{ $merchant->qb_company_name ?: $merchant->name }}</strong>.
    Your payment processor will now automatically record transactions in QuickBooks.
</p>

<div class="panel" style="max-width:540px;">
    <div class="field">
        <span>QuickBooks Company</span>
        <strong>{{ $merchant->qb_company_name ?: '—' }}</strong>
    </div>
    <div class="field">
        <span>Deposit Account</span>
        <strong>{{ $merchant->deposit_account_name ?: '—' }}</strong>
    </div>
    <div class="field">
        <span>Status</span>
        <strong><span class="badge badge-active">Active — ready to receive transactions</span></strong>
    </div>
</div>

<div style="background:rgba(19,34,56,.04);border-radius:20px;padding:18px 22px;max-width:540px;font-size:.9rem;color:#5f7089;">
    Each sales transaction from your POS system will appear as a Sales Receipt in QuickBooks within minutes.
    You do not need to do anything else — this page can be closed.
</div>

</x-booksync::layouts.master>
