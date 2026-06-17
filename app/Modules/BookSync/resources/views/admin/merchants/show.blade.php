<x-booksync::layouts.master title="{{ $merchant->name }}">

<div class="nav">
    <a href="{{ route('booksync.admin.clients.index') }}">Clients</a>
    <span class="sep">›</span>
    <a href="{{ route('booksync.admin.clients.show', $merchant->client->client_id) }}">{{ $merchant->client->name }}</a>
    <span class="sep">›</span>
    <span>{{ $merchant->name }}</span>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<p class="eyebrow">BookSync — Merchant</p>
<h1>{{ $merchant->name }}</h1>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px;">
    <div class="field">
        <span>Merchant ID</span>
        <code>{{ $merchant->merchant_id }}</code>
    </div>
    <div class="field">
        <span>Status</span>
        <strong><span class="badge badge-{{ $merchant->status }}">{{ str_replace('_', ' ', $merchant->status) }}</span></strong>
    </div>
    @if($merchant->external_merchant_id)
    <div class="field">
        <span>External Merchant ID</span>
        <strong>{{ $merchant->external_merchant_id }}</strong>
    </div>
    @endif
    @if($merchant->qb_company_name)
    <div class="field">
        <span>QuickBooks Company</span>
        <strong>{{ $merchant->qb_company_name }}</strong>
    </div>
    @endif
    @if($merchant->deposit_account_name)
    <div class="field">
        <span>Deposit Account</span>
        <strong>{{ $merchant->deposit_account_name }}</strong>
    </div>
    @endif
    @if($merchant->default_item_name)
    <div class="field">
        <span>Default Income Item</span>
        <strong>{{ $merchant->default_item_name }}</strong>
    </div>
    @endif
    @if($merchant->default_customer_name)
    <div class="field">
        <span>Default Customer</span>
        <strong>{{ $merchant->default_customer_name }}</strong>
    </div>
    @endif
    <div class="field">
        <span>Surcharge</span>
        @if($merchant->surcharge_enabled)
            <strong style="color:#1e8449;">Enabled — {{ $merchant->surcharge_item_name ?: '—' }}</strong>
        @else
            <strong style="color:#6b7280;">Disabled</strong>
        @endif
    </div>
    @if($merchant->qb_connected_at)
    <div class="field">
        <span>QB Connected</span>
        <strong>{{ $merchant->qb_connected_at->format('M d, Y g:i A') }}</strong>
    </div>
    @endif
</div>

<div class="panel">
    <h2>Setup Link</h2>
    <p class="copy" style="margin-bottom:10px;">Send this link to the merchant so they can connect their QuickBooks account.</p>
    <div class="field">
        <span>Setup URL</span>
        <code>{{ $merchant->setupLink() }}</code>
    </div>
</div>

@if($merchant->postingUrl())
<div class="panel">
    <h2>Posting URL</h2>
    <p class="copy" style="margin-bottom:10px;">The client application uses this URL to POST transaction batches.</p>
    <div class="field">
        <span>POST to this URL</span>
        <code>{{ $merchant->postingUrl() }}</code>
    </div>
</div>
@endif

@if($merchant->batches->count() > 0)
<div class="panel">
    <h2>Recent Batches</h2>
    <table class="table">
        <thead>
            <tr>
                <th>Batch ID</th>
                <th>Date</th>
                <th>Total</th>
                <th>Posted</th>
                <th>Failed</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($merchant->batches->take(20) as $batch)
            <tr>
                <td><code style="font-size:.8rem;">{{ $batch->batch_id }}</code></td>
                <td>{{ $batch->batch_date->format('M d, Y') }}</td>
                <td>{{ $batch->total_transactions }}</td>
                <td style="color:#1e8449;">{{ $batch->posted }}</td>
                <td style="color:{{ $batch->failed > 0 ? '#c0392b' : '#6b7c93' }};">{{ $batch->failed }}</td>
                <td><span class="badge badge-{{ $batch->status }}">{{ $batch->status }}</span></td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

</x-booksync::layouts.master>
