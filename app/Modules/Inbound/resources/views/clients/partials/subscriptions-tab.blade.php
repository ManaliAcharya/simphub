{{-- Subscriptions tab (Zoho CRM clients). Expects $client, $subscriptions (paginator with ->months), $subscriptionSummary, $subscriptionStatuses. --}}
@php
    $statusLabels = [
        'active'          => 'Active',
        'pending_payment' => 'Awaiting first payment',
        'cancelled'       => 'Cancelled',
    ];
    $money = fn (int $cents) => '$' . number_format($cents / 100, 2);
    $ordinal = fn (int $n) => $n . (in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th'));
    $billingDay = $client->subscriptionBillingDay();
@endphp

<style>
    .sub-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:16px; }
    .sub-stat { background:#fff; border:1px solid var(--cc-border,#e5e7eb); border-radius:10px; padding:14px 16px; }
    .sub-stat-label { font-size:12px; color:#6b7280; }
    .sub-stat-value { font-size:22px; font-weight:700; color:#111827; margin-top:4px; }
    .sub-filters { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px; }
    .sub-filters label { display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:4px; }
    .sub-filters input, .sub-filters select { padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font:inherit; font-size:13px; min-width:200px; }
    .sub-table-wrap { overflow-x:auto; }
    .sub-table { width:100%; border-collapse:collapse; font-size:13px; }
    .sub-table th { text-align:left; font-size:12px; font-weight:600; color:#6b7280; padding:8px 10px; border-bottom:1px solid #e5e7eb; white-space:nowrap; }
    .sub-table td { padding:10px; border-bottom:1px solid #f3f4f6; vertical-align:top; color:#111827; }
    .sub-muted { color:#6b7280; font-size:12px; }
    .sub-badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; font-weight:600; white-space:nowrap; }
    .sub-badge.active, .sub-badge.paid { background:#dcfce7; color:#166534; }
    .sub-badge.pending_payment, .sub-badge.unpaid { background:#fef3c7; color:#92400e; }
    .sub-badge.cancelled { background:#f3f4f6; color:#374151; }
    .sub-toggle { background:none; border:1px solid #d1d5db; border-radius:6px; padding:4px 10px; font:inherit; font-size:12px; cursor:pointer; white-space:nowrap; }
    .sub-toggle:focus-visible, .sub-filters input:focus-visible, .sub-filters select:focus-visible { outline:2px solid #2563eb; outline-offset:2px; }
    .sub-history td { background:#f9fafb; }
    .sub-history table { width:100%; border-collapse:collapse; font-size:13px; }
    .sub-history th, .sub-history td.inner { padding:6px 8px; border-bottom:1px solid #e5e7eb; text-align:left; }
    .sub-pager { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:14px; font-size:13px; flex-wrap:wrap; }
    .sub-pager a { color:#2563eb; text-decoration:none; font-weight:600; }
</style>

<div class="cc-card">
    <div class="cc-card-title">Billing day</div>
    <div class="cc-card-desc">
        Day of the month the monthly payment links are emailed to all your subscribed customers. New customers get their first link right away,
        then on this day from the next month. In months without this day (e.g. the 31st in February), links go out on the last day of the month.
    </div>

    @if (session('subscription_settings_success'))
        <div class="cc-notice success" role="status" style="margin-bottom:12px;">{{ session('subscription_settings_success') }}</div>
    @endif
    @error('subscription_billing_day')
        <div class="cc-notice error" role="alert" style="margin-bottom:12px;">{{ $message }}</div>
    @enderror

    <form method="POST" action="{{ route('inbound.clients.subscription-billing-day', $client->pms_client_id) }}"
          class="sub-filters" style="margin-bottom:0;"
          onsubmit="return this.subscription_billing_day.value == '{{ $billingDay }}' || confirm('Change the billing day? Every open subscription's next link moves to the new day (no customer is billed twice in a month).');">
        @csrf
        <div>
            <label for="sub-billing-day">Send links on the</label>
            <select id="sub-billing-day" name="subscription_billing_day" style="min-width:140px;">
                @for ($day = 1; $day <= 31; $day++)
                    <option value="{{ $day }}" @selected($day === $billingDay)>{{ $ordinal($day) }} of the month</option>
                @endfor
            </select>
        </div>
        <button type="submit" class="button primary" style="font-size:13px;">Save</button>
        @if (! $client->subscription_billing_day)
            <span class="sub-muted" style="align-self:center;">Default: 1st of the month</span>
        @endif
    </form>
</div>

<div class="sub-stats" role="list">
    <div class="sub-stat" role="listitem"><div class="sub-stat-label">Active</div><div class="sub-stat-value">{{ $subscriptionSummary['active'] }}</div></div>
    <div class="sub-stat" role="listitem"><div class="sub-stat-label">Awaiting first payment</div><div class="sub-stat-value">{{ $subscriptionSummary['pending_payment'] }}</div></div>
    <div class="sub-stat" role="listitem"><div class="sub-stat-label">Cancelled</div><div class="sub-stat-value">{{ $subscriptionSummary['cancelled'] }}</div></div>
    <div class="sub-stat" role="listitem"><div class="sub-stat-label">Unpaid months</div><div class="sub-stat-value">{{ $subscriptionSummary['unpaid_months'] }} <span class="sub-muted">({{ $money($subscriptionSummary['unpaid_cents']) }})</span></div></div>
</div>

<div class="cc-card">
    <div class="cc-card-title">Subscriptions</div>
    <div class="cc-card-desc">Every customer subscribed through your CRM, with each month's payment status. Open a row's history to see paid and unpaid months.</div>

    <form method="GET" action="{{ route('inbound.zohocrm.page') }}" class="sub-filters">
        <input type="hidden" name="pms_client_id" value="{{ $client->pms_client_id }}">
        <input type="hidden" name="tab" value="subscriptions">
        <div>
            <label for="sub-search">Search</label>
            <input id="sub-search" type="search" name="q" value="{{ request('q') }}" placeholder="Name, email, customer ID or reference">
        </div>
        <div>
            <label for="sub-status">Status</label>
            <select id="sub-status" name="status">
                <option value="">All statuses</option>
                @foreach ($subscriptionStatuses as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="button primary" style="font-size:13px;">Filter</button>
        @if (request('q') || request('status'))
            <a href="{{ route('inbound.zohocrm.page', ['pms_client_id' => $client->pms_client_id, 'tab' => 'subscriptions']) }}" style="font-size:13px;align-self:center;">Clear</a>
        @endif
    </form>

    @if ($subscriptions->isEmpty())
        <div class="sub-muted" style="padding:16px 0;">
            {{ request('q') || request('status') ? 'No subscriptions match these filters.' : 'No subscriptions yet. They appear here once your CRM calls the subscribe API.' }}
        </div>
    @else
        <div class="sub-table-wrap">
            <table class="sub-table">
                <thead>
                    <tr>
                        <th scope="col">Customer</th>
                        <th scope="col">Reference</th>
                        <th scope="col">Amount / month</th>
                        <th scope="col">Status</th>
                        <th scope="col">Paid</th>
                        <th scope="col">Unpaid</th>
                        <th scope="col">Next link</th>
                        <th scope="col"><span class="sr-only" style="position:absolute;left:-9999px;">History</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($subscriptions as $subscription)
                        @php
                            $months    = $subscription->months;
                            $paidCount = $months->where('paid', true)->count();
                            $unpaid    = $months->where('paid', false);
                            $rowId     = 'sub-history-' . $subscription->id;
                        @endphp
                        <tr>
                            <td>
                                <div style="font-weight:600;">{{ $subscription->customer_name ?: '—' }}</div>
                                <div class="sub-muted">{{ $subscription->customer_email }}</div>
                                @if ($subscription->customer_unique_id)
                                    <div class="sub-muted">ID: {{ $subscription->customer_unique_id }}</div>
                                @endif
                            </td>
                            <td><code>{{ $subscription->reference_id }}</code></td>
                            <td>{{ $money($subscription->amount_cents) }}</td>
                            <td>
                                <span class="sub-badge {{ $subscription->status }}">{{ $statusLabels[$subscription->status] ?? $subscription->status }}</span>
                                @if ($subscription->isCancelled() && $subscription->effective_date)
                                    <div class="sub-muted" style="margin-top:4px;">Ends {{ $subscription->effective_date->format('M j, Y') }}</div>
                                @endif
                            </td>
                            <td>{{ $paidCount }}</td>
                            <td>
                                {{ $unpaid->count() }}
                                @if ($unpaid->count())
                                    <div class="sub-muted">{{ $money($unpaid->sum('amount_cents')) }}</div>
                                @endif
                            </td>
                            <td>{{ $subscription->next_billing_on?->format('M j, Y') ?? '—' }}</td>
                            <td>
                                <button type="button" class="sub-toggle" aria-expanded="false" aria-controls="{{ $rowId }}"
                                        onclick="toggleSubHistory(this)">History</button>
                            </td>
                        </tr>
                        <tr class="sub-history" id="{{ $rowId }}" hidden>
                            <td colspan="8">
                                @if ($months->isEmpty())
                                    <div class="sub-muted">No monthly payment links yet.</div>
                                @else
                                    <table>
                                        <thead>
                                            <tr>
                                                <th scope="col">Month</th>
                                                <th scope="col">Amount</th>
                                                <th scope="col">Status</th>
                                                <th scope="col">Paid on</th>
                                                <th scope="col">Payment link</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($months as $month)
                                                <tr>
                                                    <td class="inner">{{ $month['label'] }}</td>
                                                    <td class="inner">{{ $money($month['amount_cents']) }}</td>
                                                    <td class="inner"><span class="sub-badge {{ $month['paid'] ? 'paid' : 'unpaid' }}">{{ $month['paid'] ? 'Paid' : 'Unpaid' }}</span></td>
                                                    <td class="inner">{{ $month['paid_at']?->format('M j, Y') ?? '—' }}</td>
                                                    <td class="inner">
                                                        @if ($month['payment_url'])
                                                            <button type="button" class="sub-toggle" data-url="{{ $month['payment_url'] }}"
                                                                    aria-label="Copy payment link for {{ $month['label'] }}"
                                                                    onclick="copySubLink(this)">Copy link</button>
                                                        @else
                                                            —
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <nav class="sub-pager" aria-label="Subscriptions pages">
            <span class="sub-muted">Showing {{ $subscriptions->firstItem() }}–{{ $subscriptions->lastItem() }} of {{ $subscriptions->total() }}</span>
            <span style="display:flex;gap:16px;">
                @if ($subscriptions->previousPageUrl())
                    <a href="{{ $subscriptions->previousPageUrl() }}">&larr; Previous</a>
                @endif
                @if ($subscriptions->nextPageUrl())
                    <a href="{{ $subscriptions->nextPageUrl() }}">Next &rarr;</a>
                @endif
            </span>
        </nav>
    @endif
</div>

<script>
    function toggleSubHistory(btn) {
        const row = document.getElementById(btn.getAttribute('aria-controls'));
        const open = btn.getAttribute('aria-expanded') === 'true';
        row.hidden = open;
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        btn.textContent = open ? 'History' : 'Hide';
    }
    function copySubLink(btn) {
        navigator.clipboard.writeText(btn.dataset.url).then(function () {
            btn.textContent = 'Copied!';
            setTimeout(function () { btn.textContent = 'Copy link'; }, 2000);
        });
    }
</script>
