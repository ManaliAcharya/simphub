<?php

namespace Modules\Inbound\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Services\PaymentSessionService;
use Modules\Inbound\Exceptions\SubscriptionException;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\Subscription;
use Modules\Payment\Services\PaymentLinkService;

/**
 * Monthly subscriptions created through the Zoho CRM subscription API.
 *
 * Each billing period becomes its own Invoice + PaymentSession, so the payment
 * page, confirmation emails and reminders work exactly as for any other invoice.
 * The first link is sent at subscribe time; later links go out on the 1st.
 */
class SubscriptionService
{
    public const PMS_SOURCE = 'zohocrm';

    public function __construct(
        private readonly PaymentSessionService $paymentSessions,
        private readonly PaymentLinkService $paymentLinks,
    ) {}

    /**
     * @return array{subscription: Subscription, payment_url: string}
     */
    public function subscribe(Client $client, array $data): array
    {
        $email    = strtolower(trim((string) $data['customer_email']));
        $uniqueId = $this->nullableTrim($data['customer_unique_id'] ?? null);
        $lockKey  = 'subscription:'.$client->pms_client_id.':'.($uniqueId ?? $email);

        // Serialise concurrent subscribe calls for the same customer so the
        // "already subscribed" check cannot race.
        return Cache::lock($lockKey, 10)->block(5, function () use ($client, $data, $email, $uniqueId) {
            if ($this->findOpen($client, $email, $uniqueId)) {
                throw new SubscriptionException('Customer already has an active subscription.', 409);
            }

            $today = CarbonImmutable::today();

            [$subscription, $invoice, $session] = DB::transaction(function () use ($client, $data, $email, $uniqueId, $today) {
                $subscription = Subscription::query()->create([
                    'pms_client_id'      => $client->pms_client_id,
                    'customer_email'     => $email,
                    'customer_name'      => $this->nullableTrim($data['customer_name'] ?? null),
                    'customer_unique_id' => $uniqueId,
                    'amount_cents'       => (int) round(((float) $data['subscription_amount']) * 100),
                    'currency'           => 'USD',
                    'billing_cycle'      => Subscription::CYCLE_MONTHLY,
                    'status'             => Subscription::STATUS_PENDING_PAYMENT,
                ]);

                $subscription->forceFill([
                    'reference_id' => sprintf('SH-SUB-%06d', $subscription->id),
                ])->save();

                [$invoice, $session] = $this->createPeriodInvoice($subscription, $today->startOfMonth());

                AuditLogger::log('SUBSCRIPTION_CREATED', 'subscription', (string) $subscription->id, [
                    'pms_client_id' => $client->pms_client_id,
                    'reference_id'  => $subscription->reference_id,
                    'amount_cents'  => $subscription->amount_cents,
                    'invoice_id'    => $invoice->id,
                ]);

                return [$subscription->fresh(), $invoice, $session];
            });

            $this->sendLink($invoice, $session, $subscription);

            return [
                'subscription' => $subscription,
                'payment_url'  => $this->paymentLinks->urlForSession($session),
            ];
        });
    }

    public function unsubscribe(Client $client, array $data): Subscription
    {
        $subscription = $this->resolveOpen($client, $data, 'Subscription is already cancelled.');

        $subscription->forceFill([
            'status'          => Subscription::STATUS_CANCELLED,
            'cancelled_at'    => now(),
            // Links already sent stay payable; nothing is billed after this month.
            'effective_date'  => CarbonImmutable::today()->endOfMonth()->toDateString(),
            'next_billing_on' => null,
        ])->save();

        AuditLogger::log('SUBSCRIPTION_CANCELLED', 'subscription', (string) $subscription->id, [
            'pms_client_id' => $client->pms_client_id,
            'reference_id'  => $subscription->reference_id,
        ]);

        return $subscription->fresh();
    }

    /**
     * Changes the monthly amount from the next billing cycle on. Links already sent
     * (including this month's) keep the amount they were sent with.
     *
     * @return array{subscription: Subscription, previous_amount_cents: int}
     */
    public function updateAmount(Client $client, array $data): array
    {
        $subscription  = $this->resolveOpen($client, $data, 'Subscription is cancelled. Subscribe the customer again instead.');
        $previousCents = $subscription->amount_cents;
        $newCents      = (int) round(((float) $data['subscription_amount']) * 100);

        if ($newCents !== $previousCents) {
            $subscription->forceFill(['amount_cents' => $newCents])->save();

            AuditLogger::log('SUBSCRIPTION_AMOUNT_UPDATED', 'subscription', (string) $subscription->id, [
                'pms_client_id'         => $client->pms_client_id,
                'reference_id'          => $subscription->reference_id,
                'previous_amount_cents' => $previousCents,
                'amount_cents'          => $newCents,
                'effective_from'        => $subscription->next_billing_on?->toDateString(),
            ]);
        }

        return [
            'subscription'          => $subscription->fresh(),
            'previous_amount_cents' => $previousCents,
        ];
    }

    /**
     * Sends the payment link for the current month if this subscription is due.
     * Safe to re-run: the per-period invoice and once-only link send are idempotent.
     */
    public function billDuePeriod(Subscription $subscription, CarbonImmutable $today): void
    {
        $periodStart = $today->startOfMonth();

        [$invoice, $session] = DB::transaction(function () use ($subscription, $periodStart, $today) {
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->first();

            // Re-check under lock: cancelled, or already billed by an overlapping run.
            if (! $locked || $locked->isCancelled() || ! $locked->next_billing_on || $locked->next_billing_on->isAfter($today)) {
                return [null, null];
            }

            return $this->createPeriodInvoice($locked, $periodStart);
        });

        if ($invoice && $session) {
            $this->sendLink($invoice, $session, $subscription->fresh());
        }
    }

    /** Moves a subscription from pending_payment to active once any of its invoices is paid. */
    public function markInvoicePaid(Invoice $invoice): void
    {
        $subscriptionId = data_get($invoice->metadata, 'subscription_id');

        if (strtolower((string) $invoice->pms_source) !== self::PMS_SOURCE || ! $subscriptionId) {
            return;
        }

        $updated = Subscription::query()
            ->whereKey($subscriptionId)
            ->where('status', Subscription::STATUS_PENDING_PAYMENT)
            ->update([
                'status'       => Subscription::STATUS_ACTIVE,
                'activated_at' => now(),
                'updated_at'   => now(),
            ]);

        if ($updated === 1) {
            AuditLogger::log('SUBSCRIPTION_ACTIVATED', 'subscription', (string) $subscriptionId, [
                'invoice_id' => $invoice->id,
            ]);
        }
    }

    /**
     * Creates (or reuses) the invoice and payment session for one billing period
     * and moves next_billing_on to the 1st of the following month.
     *
     * @return array{0: Invoice, 1: PaymentSession}
     */
    private function createPeriodInvoice(Subscription $subscription, CarbonImmutable $periodStart): array
    {
        $periodKey = $periodStart->format('Y-m');
        $reference = $subscription->reference_id.'-'.$periodKey;

        $invoice = Invoice::query()->firstOrCreate(
            [
                'pms_source'          => self::PMS_SOURCE,
                'pms_client_id'       => $subscription->pms_client_id,
                'external_invoice_id' => $reference,
            ],
            [
                'invoice_number'     => $reference,
                'external_client_id' => $subscription->customer_unique_id ?? $subscription->customer_email,
                'status'             => 'PENDING',
                'fund_type'          => 'OPERATING',
                'amount_cents'       => $subscription->amount_cents,
                'currency'           => $subscription->currency,
                'pms_sync_status'    => 'SYNCED',
                'description'        => 'Subscription – '.$periodStart->format('F Y'),
                'customer'           => array_filter([
                    'name'  => $subscription->customer_name,
                    'email' => $subscription->customer_email,
                ]),
                'recipient_emails'   => [$subscription->customer_email],
                'metadata'           => [
                    'subscription_id'           => $subscription->id,
                    'subscription_reference_id' => $subscription->reference_id,
                    'billing_period'            => $periodKey,
                ],
                'synced_at'          => now(),
            ]
        );

        $session = PaymentSession::query()
            ->where('invoice_id', $invoice->id)
            ->latest('created_at')
            ->first() ?? $this->paymentSessions->create($invoice);

        $subscription->forceFill([
            'last_billed_at'  => now(),
            'next_billing_on' => $periodStart->addMonthNoOverflow()->toDateString(),
        ])->save();

        return [$invoice, $session];
    }

    private function sendLink(Invoice $invoice, PaymentSession $session, Subscription $subscription): void
    {
        try {
            $sent = $this->paymentLinks->sendInvoiceLinkOnce($invoice, $session, [$subscription->customer_email]);

            if ($sent > 0) {
                AuditLogger::log('PAYMENT_LINK_SENT', 'payment_session', $session->id, [
                    'invoice_id'      => $invoice->id,
                    'subscription_id' => $subscription->id,
                    'emails_sent'     => $sent,
                ]);
            }
        } catch (\Throwable $e) {
            // The subscription and link exist; a mail failure must not undo them.
            Log::error('Subscription payment link email failed', [
                'subscription_id' => $subscription->id,
                'invoice_id'      => $invoice->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    private function findOpen(Client $client, string $email, ?string $uniqueId): ?Subscription
    {
        return Subscription::query()
            ->open()
            ->where('pms_client_id', $client->pms_client_id)
            ->when(
                $uniqueId !== null,
                fn ($q) => $q->where('customer_unique_id', $uniqueId),
                fn ($q) => $q->where('customer_email', $email)
            )
            ->first();
    }

    /**
     * Lookup order: simphub_reference_id, then customer_unique_id, then email + name.
     */
    private function resolveOpen(Client $client, array $data, string $cancelledMessage): Subscription
    {
        $base = fn () => Subscription::query()->where('pms_client_id', $client->pms_client_id);

        if ($referenceId = $this->nullableTrim($data['simphub_reference_id'] ?? null)) {
            $candidates = $base()->where('reference_id', $referenceId)->get();
        } elseif ($uniqueId = $this->nullableTrim($data['customer_unique_id'] ?? null)) {
            $candidates = $base()->where('customer_unique_id', $uniqueId)->get();
        } else {
            $email = strtolower(trim((string) ($data['customer_email'] ?? '')));
            $name  = mb_strtolower(trim((string) ($data['customer_name'] ?? '')));

            $candidates = $base()
                ->where('customer_email', $email)
                ->get()
                ->filter(fn (Subscription $s) => mb_strtolower(trim((string) $s->customer_name)) === $name)
                ->values();
        }

        if ($candidates->isEmpty()) {
            throw new SubscriptionException('No subscription found for this customer.', 404);
        }

        $open = $candidates->filter(fn (Subscription $s) => ! $s->isCancelled())->values();

        if ($open->isEmpty()) {
            throw new SubscriptionException($cancelledMessage, 409);
        }

        if ($open->count() > 1) {
            throw new SubscriptionException(
                'More than one active subscription matches. Send customer_unique_id or simphub_reference_id.',
                422
            );
        }

        return $open->first();
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
