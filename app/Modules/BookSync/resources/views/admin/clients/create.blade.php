<x-booksync::layouts.master title="New Client">

<div class="nav">
    <a href="{{ route('booksync.admin.clients.index') }}">Clients</a>
    <span class="sep">›</span>
    <span>New Client</span>
</div>

<p class="eyebrow">BookSync</p>
<h1>New Client</h1>
<p class="copy">A client is a software company that subscribes to BookSync and will onboard their own merchants.</p>

<div class="panel" style="max-width:520px;">
    <form method="POST" action="{{ route('booksync.admin.clients.store') }}">
        @csrf
        <div class="form-group">
            <label for="name">Company Name <span style="color:#c0392b;">*</span></label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="255" placeholder="Acme POS Inc.">
            @error('name')<div style="color:#c0392b;font-size:.85rem;margin-top:4px;">{{ $message }}</div>@enderror
        </div>
        <div class="form-group">
            <label for="contact_email">Contact Email</label>
            <input type="email" id="contact_email" name="contact_email" value="{{ old('contact_email') }}" maxlength="255" placeholder="admin@acmepos.com">
        </div>
        <div class="actions">
            <button type="submit" class="button btn-primary">Create Client</button>
            <a href="{{ route('booksync.admin.clients.index') }}" class="button btn-secondary">Cancel</a>
        </div>
    </form>
</div>

</x-booksync::layouts.master>
