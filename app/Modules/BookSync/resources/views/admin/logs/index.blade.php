<x-booksync::layouts.master title="API Audit Logs">

<div class="nav">
    <a href="{{ route('booksync.admin.clients.index') }}">Clients</a>
    <span class="sep">›</span>
    <span>API Logs</span>
</div>

<p class="eyebrow">BookSync — Admin</p>
<h1>API Audit Logs</h1>
<p class="copy" style="margin-bottom:20px;">Every inbound transaction posting request — successes, failures, and rejected attempts.</p>

{{-- ── Filters ── --}}
<form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;align-items:flex-end;">
    <div>
        <label style="font-size:.78rem;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">HTTP Status</label>
        <select name="status" style="font-size:.85rem;padding:6px 10px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;">
            <option value="">All</option>
            <option value="200" {{ request('status')=='200'?'selected':'' }}>200 OK</option>
            <option value="401" {{ request('status')=='401'?'selected':'' }}>401 Unauthorized</option>
            <option value="403" {{ request('status')=='403'?'selected':'' }}>403 Forbidden</option>
            <option value="404" {{ request('status')=='404'?'selected':'' }}>404 Not Found</option>
            <option value="409" {{ request('status')=='409'?'selected':'' }}>409 All Duplicate</option>
            <option value="422" {{ request('status')=='422'?'selected':'' }}>422 Validation</option>
        </select>
    </div>
    <div>
        <label style="font-size:.78rem;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">Outcome</label>
        <select name="rejection_reason" style="font-size:.85rem;padding:6px 10px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;">
            <option value="">All</option>
            <option value="success" {{ request('rejection_reason')=='success'?'selected':'' }}>Success</option>
            <option value="signature_invalid" {{ request('rejection_reason')=='signature_invalid'?'selected':'' }}>Invalid Signature</option>
            <option value="timestamp_stale" {{ request('rejection_reason')=='timestamp_stale'?'selected':'' }}>Stale Timestamp</option>
            <option value="timestamp_missing" {{ request('rejection_reason')=='timestamp_missing'?'selected':'' }}>Missing Timestamp</option>
            <option value="signature_missing" {{ request('rejection_reason')=='signature_missing'?'selected':'' }}>Missing Signature</option>
            <option value="merchant_not_found" {{ request('rejection_reason')=='merchant_not_found'?'selected':'' }}>Merchant Not Found</option>
            <option value="merchant_not_active" {{ request('rejection_reason')=='merchant_not_active'?'selected':'' }}>Merchant Not Active</option>
            <option value="no_signing_secret" {{ request('rejection_reason')=='no_signing_secret'?'selected':'' }}>No Signing Secret</option>
        </select>
    </div>
    <div style="display:flex;gap:8px;">
        <button type="submit" class="button btn-primary" style="font-size:.83rem;padding:7px 16px;">Filter</button>
        <a href="{{ route('booksync.admin.logs.index') }}" class="button btn-secondary" style="font-size:.83rem;padding:7px 16px;">Clear</a>
    </div>
</form>

{{-- ── Table ── --}}
<div class="panel" style="padding:0;overflow:hidden;">
    @if($logs->isEmpty())
        <p style="padding:24px;color:#9ca3af;text-align:center;">No log entries found.</p>
    @else
        <table class="table" style="margin:0;border-radius:0;border:none;">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Client</th>
                    <th>Merchant</th>
                    <th>IP</th>
                    <th>Status</th>
                    <th>Outcome</th>
                    <th>Batch ID</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($logs as $log)
                <tr>
                    <td style="white-space:nowrap;font-size:.8rem;color:#6b7280;">
                        {{ $log->created_at->format('M d, H:i:s') }}
                    </td>
                    <td style="font-size:.82rem;">
                        {{ $log->client?->name ?? '—' }}
                    </td>
                    <td style="font-size:.82rem;">
                        {{ $log->merchant?->name ?? '—' }}
                    </td>
                    <td><code style="font-size:.78rem;">{{ $log->ip_address }}</code></td>
                    <td>
                        @php
                            $s = $log->http_status;
                            $color = $s < 300 ? '#1e8449' : ($s < 500 ? '#b7791f' : '#c0392b');
                        @endphp
                        <strong style="color:{{ $color }}">{{ $s }}</strong>
                    </td>
                    <td style="font-size:.8rem;">
                        @if(!$log->rejection_reason)
                            <span style="color:#1e8449;font-weight:600;">success</span>
                        @else
                            <span style="color:#c0392b;">{{ $log->rejection_reason }}</span>
                        @endif
                    </td>
                    <td style="font-size:.78rem;color:#6b7280;font-family:ui-monospace,monospace;">
                        {{ $log->batch_id ?? '—' }}
                    </td>
                    <td>
                        <button class="button btn-secondary"
                            style="font-size:.75rem;padding:3px 10px;"
                            onclick="showBody({{ $log->id }}, {{ json_encode($log->request_body) }}, {{ json_encode($log->timestamp_header) }}, {{ json_encode($log->signature_header) }})">
                            Body
                        </button>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div style="padding:16px 20px;border-top:1px solid #f3f4f6;">
            {{ $logs->links() }}
        </div>
    @endif
</div>

{{-- ── Body modal ── --}}
<div id="body-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;width:min(740px,94vw);max-height:85vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.25);">
        <div style="padding:16px 20px;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;">
            <strong style="font-size:.95rem;">Request Body</strong>
            <button onclick="closeModal()" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#6b7280;">✕</button>
        </div>
        <div style="padding:16px 20px;overflow-y:auto;flex:1;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
                <div>
                    <p style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin:0 0 4px;">X-BookSync-Timestamp</p>
                    <code id="modal-ts" style="font-size:.82rem;word-break:break-all;">—</code>
                </div>
                <div>
                    <p style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin:0 0 4px;">X-BookSync-Signature</p>
                    <code id="modal-sig" style="font-size:.75rem;word-break:break-all;">—</code>
                </div>
            </div>
            <p style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin:0 0 6px;">Raw Body</p>
            <pre id="modal-body" style="background:#1e293b;color:#e2e8f0;padding:16px;border-radius:8px;font-size:12px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;margin:0;"></pre>
        </div>
    </div>
</div>

<script>
function showBody(id, body, ts, sig) {
    document.getElementById('modal-ts').textContent  = ts  || '—';
    document.getElementById('modal-sig').textContent = sig || '—';
    try {
        document.getElementById('modal-body').textContent = body ? JSON.stringify(JSON.parse(body), null, 2) : '(empty)';
    } catch(e) {
        document.getElementById('modal-body').textContent = body || '(empty)';
    }
    const m = document.getElementById('body-modal');
    m.style.display = 'flex';
}
function closeModal() {
    document.getElementById('body-modal').style.display = 'none';
}
document.getElementById('body-modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

</x-booksync::layouts.master>
