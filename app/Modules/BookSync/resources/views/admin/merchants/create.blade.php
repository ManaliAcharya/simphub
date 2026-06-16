<x-booksync::layouts.master title="New Merchant">

<div class="nav">
    <a href="{{ route('booksync.admin.clients.index') }}">Clients</a>
    <span class="sep">›</span>
    <a href="{{ route('booksync.admin.clients.show', $client->client_id) }}">{{ $client->name }}</a>
    <span class="sep">›</span>
    <span>New Merchant</span>
</div>

<p class="eyebrow">BookSync — {{ $client->name }}</p>
<h1>Add Merchant</h1>
<p class="copy">A merchant is a business that has a QuickBooks account and processes transactions through the client's POS system.</p>

<div class="panel" style="max-width:520px;">
    <form method="POST" action="{{ route('booksync.admin.merchants.store', $client->client_id) }}">
        @csrf
        <div class="form-group">
            <label for="name">Business Name <span style="color:#c0392b;">*</span></label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="255" placeholder="Downtown Auto Parts">
            @error('name')<div style="color:#c0392b;font-size:.85rem;margin-top:4px;">{{ $message }}</div>@enderror
        </div>
        <div class="form-group">
            <label for="merchant_email">Merchant Email</label>
            <input type="email" id="merchant_email" name="merchant_email" value="{{ old('merchant_email') }}" placeholder="owner@merchant.com">
        </div>
        <div class="form-group">
            <label for="external_merchant_id">External Merchant ID</label>
            <input type="text" id="external_merchant_id" name="external_merchant_id" value="{{ old('external_merchant_id') }}" maxlength="100" placeholder="POS-MERCH-4421">
            <div style="color:#6b7c93;font-size:.82rem;margin-top:4px;">Your system's own ID for this merchant — stored for reference.</div>
        </div>
        <div class="actions">
            <button type="submit" class="button btn-primary">Create Merchant</button>
            <a href="{{ route('booksync.admin.clients.show', $client->client_id) }}" class="button btn-secondary">Cancel</a>
        </div>
    </form>
</div>

</x-booksync::layouts.master>
