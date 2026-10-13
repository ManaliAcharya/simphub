<?php

namespace Modules\Inbound\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\Subscription;
use Modules\Payment\Services\PaymentLinkService;

/**
 * Read-only view of a client's subscriptions and each one's month-by-month
 * paid / unpaid history, for the client's config page.
 */
class SubscriptionHistoryService
{
    public function __construct(
        private readonly PaymentLinkService $paymentLinks,
    ) {}

    /**
     * Subscriptions for the client, newest first, each with a `months` collection of
     * ['period', 'label', 'amount_cents', 'paid', 'paid_at', 'payment_url'] (newest month first).
     */
    public function paginate(Client $client, ?string $search, ?string $status, int $perPage = 20): LengthAwarePaginator
    {
        $search = trim((string) $search);

        $subscriptions = Subscription::query()
            ->where('pms_client_id', $client->pms_client_id)
            ->when(in_array($status, $this->statuses(), true), fn ($q) => $q->where('status', $status))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';

                $q->where(fn ($q) => $q
                    ->where('customer_email', 'like', $like)
                    ->orWhere('customer_name', 'like', $like)
                    ->orWhere('customer_unique_id', 'like', $like)
                    ->orWhere('reference_id', 'like', $like));
            })
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        $months = $this->monthsFor($client, $subscriptions->getCollection());

        $subscriptions->getCollection()->each(function (Subscription $subscription) use ($months) {
            $subscription->setRelation('months', $months->get($subscription->reference_id, collect()));
        });

        return $subscriptions;
    }

    /** Totals across all of the client's subscriptions (not just the current page). */
    public function summary(Client $client): array
    {
        $counts = Subscription::query()
            ->where('pms_client_id', $client->pms_client_id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $unpaid = Invoice::query()
            ->where('pms_source', SubscriptionService::PMS_SOURCE)
            ->where('pms_client_id', $client->pms_client_id)
            ->where('status', '!=', 'PAID')
            ->selectRaw('count(*) as months, coalesce(sum(amount_cents), 0) as cents')
            ->first();

        return [
            'active'          => (int) ($counts[Subscription::STATUS_ACTIVE] ?? 0),
            'pending_payment' => (int) ($counts[Subscription::STATUS_PENDING_PAYMENT] ?? 0),
            'cancelled'       => (int) ($counts[Subscription::STATUS_CANCELLED] ?? 0),
            'unpaid_months'   => (int) ($unpaid->months ?? 0),
            'unpaid_cents'    => (int) ($unpaid->cents ?? 0),
        ];
    }

    public function statuses(): array
    {
        return [
            Subscription::STATUS_ACTIVE,
            Subscription::STATUS_PENDING_PAYMENT,
            Subscription::STATUS_CANCELLED,
        ];
    }

    /**
     * Monthly invoices are keyed "<reference_id>-<YYYY-MM>", so one query by
     * prefix fetches every month for the subscriptions on this page.
     *
     * @return Collection<string, Collection<int, array>>  keyed by reference_id
     */
    private function monthsFor(Client $client, Collection $subscriptions): Collection
    {
        $references = $subscriptions->pluck('reference_id')->filter()->values();

        if ($references->isEmpty()) {
            return collect();
        }

        $invoices = Invoice::query()
            ->where('pms_source', SubscriptionService::PMS_SOURCE)
            ->where('pms_client_id', $client->pms_client_id)
            ->where(function ($q) use ($references) {
                foreach ($references as $reference) {
                    $q->orWhere('external_invoice_id', 'like', $reference.'-%');
                }
            })
            ->get(['id', 'external_invoice_id', 'amount_cents', 'status', 'metadata']);

        $sessions = PaymentSession::query()
            ->whereIn('invoice_id', $invoices->pluck('id'))
            ->latest('created_at')
            ->get(['id', 'invoice_id', 'hosted_url_token', 'link_status', 'completed_at'])
            ->unique('invoice_id')
            ->keyBy('invoice_id');

        return $invoices
            ->map(function (Invoice $invoice) use ($sessions) {
                $period  = (string) data_get($invoice->metadata, 'billing_period', substr($invoice->external_invoice_id, -7));
                $session = $sessions->get($invoice->id);
                $paid    = strtoupper((string) $invoice->status) === 'PAID';

                return [
                    'reference'    => substr($invoice->external_invoice_id, 0, -8),
                    'period'       => $period,
                    'label'        => $this->periodLabel($period),
                    'amount_cents' => (int) $invoice->amount_cents,
                    'paid'         => $paid,
                    'paid_at'      => $paid ? $session?->completed_at : null,
                    'payment_url'  => ! $paid && $session && $session->link_status === 'active'
                        ? $this->paymentLinks->urlForSession($session)
                        : null,
                ];
            })
            ->sortByDesc('period')
            ->groupBy('reference')
            ->map(fn (Collection $months) => $months->values());
    }

    private function periodLabel(string $period): string
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m', $period)->format('F Y');
        } catch (\Throwable) {
            return $period;
        }
    }
}
