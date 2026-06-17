<x-booksync::layouts.master title="Setup Complete">

<p class="eyebrow">BookSync Setup — Complete</p>
<h1>You're all set!</h1>
<p class="copy">
    QuickBooks has been connected for <strong>{{ $merchant->qb_company_name ?: $merchant->name }}</strong>.
    Your payment processor will now automatically record transactions in QuickBooks.
</p>

<div class="panel" style="max-width:540px;margin-bottom:16px;">
    <div class="field">
        <span>QuickBooks Company</span>
        <strong>{{ $merchant->qb_company_name ?: '—' }}</strong>
    </div>
    <div class="field">
        <span>Status</span>
        <strong><span class="badge badge-active">Active — ready to receive transactions</span></strong>
    </div>
</div>

<div class="panel" style="max-width:540px;margin-bottom:16px;">
    <p style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin:0 0 14px;">Account Settings</p>

    <div class="field">
        <span>Deposit Account <span style="font-weight:400;color:#9ca3af;font-size:.8rem;">— debit</span></span>
        <strong>{{ $merchant->deposit_account_name ?: '—' }}</strong>
    </div>
    <div class="field">
        <span>Default Item</span>
        <strong>{{ $merchant->default_item_name ?: '—' }}</strong>
    </div>
    <div class="field">
        <span>Default Customer</span>
        <strong>{{ $merchant->default_customer_name ?: '—' }}</strong>
    </div>
    <div class="field">
        <span>Surcharge</span>
        @if($merchant->surcharge_enabled)
            <strong style="color:#1e8449;">Enabled — {{ $merchant->surcharge_item_name ?: '—' }}</strong>
        @else
            <strong style="color:#6b7280;">Disabled</strong>
        @endif
    </div>

    <div style="margin-top:16px;padding-top:14px;border-top:1px solid #f3f4f6;">
        <a href="{{ route('booksync.setup.edit', $merchant->setup_token) }}"
            class="button btn-secondary" style="font-size:.85rem;padding:8px 18px;">
            Update Account Settings
        </a>
    </div>
</div>

<div style="background:rgba(19,34,56,.04);border-radius:20px;padding:18px 22px;max-width:540px;font-size:.9rem;color:#5f7089;">
    Each sales transaction from your POS system will appear as a Sales Receipt in QuickBooks within minutes.
    You can update your account preferences at any time using the button above.
</div>

</x-booksync::layouts.master>
