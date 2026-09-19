<x-inbound::layouts.master>
    <style>
        body {
            font-family: -apple-system, Segoe UI, Roboto, sans-serif;
            background: #f7f8fa;
            margin: 0;
        }

        .page-header {
            background: #1a1a2e;
            padding: 20px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .page-header h1 {
            color: #fff;
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }

        .page-header .sub {
            color: rgba(255, 255, 255, .55);
            font-size: 13px;
            margin-top: 2px;
        }

        .page-body {
            padding: 28px 32px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 9px;
            padding: 9px 18px;
            font: inherit;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background .15s;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        /* ── Search ── */
        .toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .search-wrap {
            position: relative;
            flex: 1;
            min-width: 220px;
            max-width: 360px;
        }

        .search-wrap input {
            width: 100%;
            padding: 9px 12px 9px 36px;
            border: 1px solid #d1d5db;
            border-radius: 9px;
            font: inherit;
            font-size: 13px;
            background: #fff;
            box-sizing: border-box;
        }

        .search-wrap input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
        }

        .search-wrap .icon {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            pointer-events: none;
        }

        .count-badge {
            font-size: 13px;
            color: #6b7280;
        }

        /* ── Table ── */
        .client-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .client-table th {
            background: #f1f5f9;
            color: #374151;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            padding: 10px 14px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
        }

        .client-table th:first-child {
            border-radius: 10px 0 0 0;
        }

        .client-table th:last-child {
            border-radius: 0 10px 0 0;
        }

        .client-table td {
            padding: 13px 14px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
            color: #374151;
            background: #fff;
            vertical-align: middle;
        }

        .client-table tr:last-child td {
            border-bottom: none;
        }

        .client-table tr:hover td {
            background: #fafbfc;
        }

        .client-table-wrap {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 6px rgba(0, 0, 0, .06);
        }

        .client-name {
            font-weight: 600;
            color: #111827;
        }

        .pms-badge {
            display: inline-block;
            padding: 2px 9px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .pms-quickbooks {
            background: #d1fae5;
            color: #065f46;
        }

        .pms-clio {
            background: #dbeafe;
            color: #1e40af;
        }

        .pms-zoho {
            background: #ede9fe;
            color: #4c1d95;
        }

        .pms-wave {
            background: #fef3c7;
            color: #92400e;
        }

        .pms-lawcus {
            background: #fce7f3;
            color: #9d174d;
        }

        .pms-mindbody {
            background: #ecfdf5;
            color: #065f46;
        }

        .pms-custom {
            background: #f1f5f9;
            color: #374151;
        }

        .gateway-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }

        .gateway-tag {
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            background: #f0f9ff;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }

        .gateway-tag.paused {
            background: #fef2f2;
            color: #b91c1c;
            border-color: #fecaca;
            text-decoration: line-through;
        }

        .status-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 5px;
        }

        .status-on {
            background: #16a34a;
        }

        .status-off {
            background: #d1d5db;
        }

        .action-btn {
            padding: 6px 14px;
            border-radius: 7px;
            font: inherit;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all .15s;
            border: 1.5px solid #d1d5db;
            background: #fff;
            color: #374151;
            display: inline-block;
        }

        .action-btn:hover {
            border-color: #2563eb;
            color: #2563eb;
            background: #eff6ff;
        }

        .empty-state {
            text-align: center;
            padding: 64px 32px;
            color: #6b7280;
        }

        .empty-state .icon {
            font-size: 40px;
            margin-bottom: 12px;
        }

        .empty-state p {
            margin: 0 0 20px;
            font-size: 15px;
        }

        .custom-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .45);
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .modal-box {
            width: 420px;
            background: #fff;
            border-radius: 12px;
            padding: 28px;
            text-align: center;
            box-shadow: 0 15px 40px rgba(0, 0, 0, .2);
        }

        .modal-icon {
            width: 60px;
            height: 60px;
            margin: auto;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 15px;
        }

        .modal-box h3 {
            margin: 0 0 10px;
            font-size: 22px;
        }

        .modal-box p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 25px;
        }

        .modal-footer {
            display: flex;
            justify-content: center;
            gap: 12px;
        }

        .btn-cancel {
            padding: 10px 20px;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 8px;
            cursor: pointer;
        }

        #confirmBtn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .status-badge .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 6px;
        }

        .status-badge.active {
            background: #ecfdf5;
            color: #166534;
        }

        .status-badge.active .dot {
            background: #22c55e;
        }

        .status-badge.inactive {
            background: #fef2f2;
            color: #991b1b;
        }

        .status-badge.inactive .dot {
            background: #ef4444;
        }

        .toast-success {
            position: fixed;
            top: 25px;
            right: 25px;
            background: #16a34a;
            color: #fff;
            padding: 14px 18px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, .18);
            z-index: 9999;
            animation: slideIn .3s ease;
        }

        .toast-icon {
            font-size: 18px;
            font-weight: bold;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(40px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
    </style>

    <div class="page-header">
        <div>
            <h1>Clients</h1>
            <div class="sub">Manage all connected PMS clients and their configurations</div>
        </div>
        <a href="{{ route('inbound.clients.create') }}" class="btn-primary">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
            </svg>
            New Client
        </a>
    </div>

    <div class="page-body">

        <div class="toolbar">
            <div class="search-wrap">
                <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"
                    viewBox="0 0 24 24">
                    <circle cx="11" cy="11" r="8" />
                    <path d="M21 21l-4.35-4.35" />
                </svg>
                <input type="text" id="client-search" placeholder="Search by name or PMS…"
                    oninput="filterClients(this.value)">
            </div>
            <div class="count-badge" id="client-count">{{ $clients->count() }} clients</div>
        </div>


        @if ($clients->isEmpty())
            <div class="empty-state">
                <div class="icon">🏢</div>
                <p>No clients yet. Create your first client to get started.</p>
                <a href="{{ route('inbound.clients.create') }}" class="btn-primary">Create first client</a>
            </div>
        @else
            <div class="client-table-wrap">
                <table class="client-table" id="client-table">
                    <thead>
                        <tr>
                            <th>Client Name</th>
                            <th>PMS</th>
                            <th>Gateways</th>
                            <th>Surcharge</th>
                            <th>Call API</th>
                            <th>Status</th>
                            <th>Actions</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($clients as $client)
                            @php
                                $isBoarding = $client instanceof \Modules\Boarding\Models\BoardingClient;
                                $displayName = $isBoarding ? $client->name : $client->client_name;
                                $displayId = $isBoarding ? $client->client_id : $client->pms_client_id;
                                $pms = $isBoarding ? 'boarding' : strtolower($client->client_pms ?? 'custom');
                                $allowed = $isBoarding ? [] : array_map('strtolower', $client->allowed_payment_gateways ?? []);
                                $paused = $isBoarding ? [] : array_map('strtolower', $client->paused_payment_gateways ?? []);
                                $configUrl = clientConfigUrl($client);
                            @endphp
                            <tr class="client-row" data-name="{{ strtolower($displayName) }}"
                                data-pms="{{ $pms }}">
                                <td>
                                    <div class="client-name">{{ $displayName }}</div>
                                    <div class="client-email">{{ $client->account?->email }}</div>
                                    <div style="font-size:11px;color:#9ca3af;margin-top:2px;">
                                        {{ $displayId }}</div>
                                </td>
                                <td>
                                    <span
                                        class="pms-badge pms-{{ $pms }}">{{ $isBoarding ? 'BOARDING' : strtoupper($client->client_pms ?? 'Custom') }}</span>
                                </td>
                                <td>
                                    @if ($isBoarding)
                                        <span style="color:#9ca3af;font-size:12px;">N/A</span>
                                    @elseif (count($allowed))
                                        <div class="gateway-tags">
                                            @foreach ($allowed as $gw)
                                                <span class="gateway-tag {{ in_array($gw, $paused) ? 'paused' : '' }}">
                                                    {{ strtoupper($gw) }}{{ in_array($gw, $paused) ? ' ⏸' : '' }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @else
                                        <span style="color:#9ca3af;font-size:12px;">None</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($isBoarding)
                                        <span style="color:#9ca3af;font-size:12px;">N/A</span>
                                    @else
                                        <span
                                            class="status-dot {{ $client->fee_surcharge_enabled ? 'status-on' : 'status-off' }}"></span>
                                        {{ $client->fee_surcharge_enabled ? 'On' : 'Off' }}
                                        @if ($client->fee_surcharge_enabled && ($client->cc_fee_percent || $client->ach_fee_percent))
                                            <div style="font-size:11px;color:#6b7280;margin-top:1px;">
                                                CC {{ number_format($client->cc_fee_percent ?? 0, 2) }}% &nbsp; ACH
                                                {{ number_format($client->ach_fee_percent ?? 0, 2) }}%
                                            </div>
                                        @endif
                                    @endif
                                </td>
                                <td>
                                    @if ($isBoarding)
                                        <span style="color:#9ca3af;font-size:12px;">N/A</span>
                                    @else
                                        <span
                                            class="status-dot {{ $client->call_api_to_pms ? 'status-on' : 'status-off' }}"></span>
                                        {{ $client->call_api_to_pms ? 'Yes' : 'No' }}
                                    @endif
                                </td>
                                {{-- <td>
                        <a href="{{ route('inbound.clients.update-status', ['client_id' => $client->id]) }}" class="action-btn">Inactivate</a>
                        <form action="{{ route('inbound.clients.destroy', ['client_id' => $client->id]) }}" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this client?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="action-btn" style="background:#fef2f; color:#b91c1c; border-color:#fecaca;">Delete</button>
                        </form>
                    </td> --}}

                                @php
                                    $isActive = $client->account?->is_active ?? 0;
                                @endphp

                                <td>
                                    @if ($isActive)
                                        <span class="status-badge active">
                                            <span class=""></span> Active
                                        </span>
                                    @else
                                        <span class="status-badge inactive">
                                            <span class=""></span> Inactive
                                        </span>
                                    @endif
                                </td>

                                <td>


                                    <!-- Status -->
                                    <a href="javascript:void(0)" class="action-btn btn-status"
                                        data-url="{{ route('inbound.clients.update-status', ['client_id' => $client->id]) }}"
                                        data-client="{{ $displayName }}"
                                        data-action="{{ $isActive == 1 ? 'Inactivate' : 'Activate' }}">

                                        {{ $isActive == 1 ? 'Inactivate' : 'Activate' }}
                                    </a>

                                    <!-- Delete -->
                                    <button type="button" class="action-btn btn-delete"
                                        data-url="{{ route('inbound.clients.destroy', ['client_id' => $client->id]) }}"
                                        data-client="{{ $displayName }}">
                                        Delete
                                    </button>
                                </td>
                                <td style="text-align:right;">
                                    <a href="{{ $configUrl }}" class="action-btn">View config →</a>

                                    @if ($client->account && auth()->user()?->is_super_admin)
                                        <form action="{{ route('admin.impersonate.start', $client->account->id) }}" method="POST" style="display:inline;">
                                            @csrf
                                            <button type="submit" class="action-btn"
                                                title="Opens a read-only, audited view of this client's portal — no client login needed.">
                                                View as client →
                                            </button>
                                        </form>
                                    @endif

                                    @if ($isBoarding)
                                        <a href="{{ route('inbound.clients.boarding.api-docs', $client->client_id) }}" class="action-btn">API Docs →</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

    </div>

    @if (session('success'))
        <div id="toast" class="toast-success">
            <span class="toast-icon">✓</span>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <div id="confirmModal" class="custom-modal">
        <div class="modal-box">

            <div class="modal-icon" id="modalIcon">
                ⚠️
            </div>

            <h3 id="modalTitle">Confirm Action</h3>

            <p id="modalMessage"></p>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal()">
                    Cancel
                </button>

                <form id="confirmForm" method="POST">
                    @csrf
                    <input type="hidden" name="_method" id="formMethod">
                    <button type="submit" id="confirmBtn">
                        Confirm
                    </button>
                </form>
            </div>

        </div>
    </div>

    <script>
        const modal = document.getElementById('confirmModal');

        function closeModal() {
            modal.style.display = "none";
        }

        document.querySelectorAll('.btn-delete').forEach(btn => {

            btn.onclick = function() {

                document.getElementById('modalTitle').innerHTML = "Delete Client";

                document.getElementById('modalMessage').innerHTML =
                    "Are you sure you want to delete <b>" + this.dataset.client + "</b>?";

                document.getElementById('confirmForm').action = this.dataset.url;

                document.getElementById('formMethod').value = "DELETE";

                document.getElementById('confirmBtn').innerHTML = "Delete";

                document.getElementById('confirmBtn').style.background = "#dc2626";

                document.getElementById('modalIcon').style.background = "#fee2e2";

                document.getElementById('modalIcon').innerHTML = "🗑️";

                modal.style.display = "flex";
            };

        });


        document.querySelectorAll('.btn-status').forEach(btn => {

            btn.onclick = function() {

                let action = this.dataset.action;

                document.getElementById('modalTitle').innerHTML = action + " Client";

                document.getElementById('modalMessage').innerHTML =
                    "Are you sure you want to <b>" + action.toLowerCase() + "</b> <b>" + this.dataset.client +
                    "</b>?";

                document.getElementById('confirmForm').action = this.dataset.url;

                document.getElementById('formMethod').value = "PATCH";

                document.getElementById('confirmBtn').innerHTML = action;

                document.getElementById('confirmBtn').style.background = "#2563eb";

                document.getElementById('modalIcon').style.background = "#dbeafe";

                document.getElementById('modalIcon').innerHTML = "🔄";

                modal.style.display = "flex";
            };

        });


        window.onclick = function(e) {

            if (e.target == modal) {

                closeModal();

            }

        }

        const toast = document.getElementById('toast');

        if (toast) {
            setTimeout(() => {
                toast.style.transition = 'opacity .4s';
                toast.style.opacity = 0;

                setTimeout(() => toast.remove(), 400);
            }, 3000);
        }

        function filterClients(q) {
            q = q.toLowerCase().trim();
            var rows = document.querySelectorAll('.client-row');
            var shown = 0;
            rows.forEach(function(row) {
                var match = !q || row.dataset.name.includes(q) || row.dataset.pms.includes(q);
                row.style.display = match ? '' : 'none';
                if (match) shown++;
            });
            document.getElementById('client-count').textContent = shown + ' client' + (shown !== 1 ? 's' : '');
        }
    </script>
</x-inbound::layouts.master>
