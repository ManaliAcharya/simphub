<x-booksync::layouts.master title="New Client">

<div class="nav">
    <a href="{{ route('booksync.admin.clients.index') }}">Clients</a>
    <span class="sep">›</span>
    <span>New Client</span>
</div>

<p class="eyebrow">BookSync</p>
<h1>New Client</h1>
<p class="copy">A client is a software company that subscribes to BookSync and will onboard their own merchants.</p>

<style>
    .connector-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-top:8px;}
    .connector-card{position:relative;border:2px solid #e5e7eb;border-radius:10px;padding:16px;cursor:pointer;transition:border-color .15s,box-shadow .15s;background:#fff;}
    .connector-card:hover{border-color:#93c5fd;}
    .connector-card input[type=radio]{position:absolute;opacity:0;width:0;height:0;}
    .connector-card input[type=radio]:checked ~ .card-inner{color:inherit;}
    .connector-card:has(input:checked){border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12);background:#eff6ff;}
    .card-inner{pointer-events:none;}
    .card-name{font-weight:700;font-size:.95rem;color:#111827;margin-bottom:4px;}
    .card-desc{font-size:.82rem;color:#6b7280;line-height:1.5;}
    .card-check{position:absolute;top:10px;right:10px;width:18px;height:18px;border-radius:50%;border:2px solid #d1d5db;background:#fff;display:flex;align-items:center;justify-content:center;transition:all .15s;}
    .connector-card:has(input:checked) .card-check{border-color:#2563eb;background:#2563eb;}
    .card-check svg{display:none;}
    .connector-card:has(input:checked) .card-check svg{display:block;}
</style>

<div class="panel" style="max-width:560px;">
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

        <div class="form-group">
            <label>Accounting System <span style="color:#c0392b;">*</span></label>
            <p style="font-size:.85rem;color:#6b7280;margin:2px 0 10px;">Choose which accounting software this client's merchants will connect to.</p>
            <div class="connector-grid">
                @foreach($connectors as $connector)
                <label class="connector-card">
                    <input type="radio" name="accounting_system" value="{{ $connector->key() }}"
                        {{ old('accounting_system', 'quickbooks') === $connector->key() ? 'checked' : '' }}>
                    <div class="card-check">
                        <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                            <path d="M2 5l2.5 2.5L8 3" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="card-inner">
                        <div class="card-name">{{ $connector->label() }}</div>
                        <div class="card-desc">{{ $connector->description() }}</div>
                    </div>
                </label>
                @endforeach
            </div>
            @error('accounting_system')<div style="color:#c0392b;font-size:.85rem;margin-top:6px;">{{ $message }}</div>@enderror
        </div>

        <div class="actions">
            <button type="submit" class="button btn-primary">Create Client</button>
            <a href="{{ route('booksync.admin.clients.index') }}" class="button btn-secondary">Cancel</a>
        </div>
    </form>
</div>

</x-booksync::layouts.master>
