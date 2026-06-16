<x-booksync::layouts.master title="Clients">

<p class="eyebrow">BookSync</p>
<h1>Clients</h1>
<p class="copy">Each client is a software company (POS, CRM, etc.) subscribed to BookSync. Merchants are created under clients.</p>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="actions" style="margin-bottom:20px;">
    <a href="{{ route('booksync.admin.clients.create') }}" class="button btn-primary">+ New Client</a>
</div>

<div class="panel" style="padding:0;overflow:hidden;">
    @if($clients->isEmpty())
        <div style="padding:28px;text-align:center;color:#6b7c93;">No clients yet. Create your first client to get started.</div>
    @else
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Client ID</th>
                    <th>Merchants</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($clients as $client)
                <tr>
                    <td><strong>{{ $client->name }}</strong></td>
                    <td><code style="font-size:.82rem;">{{ $client->client_id }}</code></td>
                    <td>{{ $client->merchants_count }}</td>
                    <td><span class="badge badge-{{ $client->status }}">{{ $client->status }}</span></td>
                    <td style="color:#6b7c93;font-size:.85rem;">{{ $client->created_at->format('M d, Y') }}</td>
                    <td><a href="{{ route('booksync.admin.clients.show', $client->client_id) }}" class="button btn-secondary" style="padding:6px 14px;font-size:.82rem;">View</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

</x-booksync::layouts.master>
