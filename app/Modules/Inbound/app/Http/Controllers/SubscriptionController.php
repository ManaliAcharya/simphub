<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\Inbound\Exceptions\SubscriptionException;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\Subscription;
use Modules\Inbound\Services\SubscriptionService;

class SubscriptionController extends Controller
{
    // Billing is always monthly, so subscribe takes no billing_cycle (ignored if sent).
    private const AMOUNT_RULES = ['required', 'numeric', 'decimal:0,2', 'min:0.50', 'max:999999.99'];

    /** How unsubscribe / update-amount identify a subscription. */
    private const LOOKUP_RULES = [
        'simphub_reference_id' => ['nullable', 'string', 'max:32'],
        'customer_unique_id'   => ['nullable', 'string', 'max:255'],
        'customer_email'       => ['required_without_all:simphub_reference_id,customer_unique_id', 'nullable', 'email', 'max:255'],
        'customer_name'        => ['required_without_all:simphub_reference_id,customer_unique_id', 'nullable', 'string', 'max:255'],
    ];

    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function subscribe(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_email'      => ['required', 'email', 'max:255'],
            'customer_name'       => ['nullable', 'string', 'max:255'],
            'customer_unique_id'  => ['nullable', 'string', 'max:255'],
            'subscription_amount' => self::AMOUNT_RULES,
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        return $this->handle(function () use ($request, $validator) {
            $result = $this->subscriptions->subscribe($this->client($request), $validator->validated());

            return response()->json([
                'success' => true,
                'message' => 'Subscription created successfully.',
                'data'    => [
                    ...$this->customerData($result['subscription']),
                    'subscription_amount' => $result['subscription']->amountDecimal(),
                    'billing_cycle'       => $result['subscription']->billing_cycle,
                    'status'              => $result['subscription']->status,
                    'payment_link'        => $result['payment_url'],
                    'created_at'          => $result['subscription']->created_at?->toIso8601ZuluString(),
                ],
            ], 201);
        });
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), self::LOOKUP_RULES);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        return $this->handle(function () use ($request, $validator) {
            $subscription = $this->subscriptions->unsubscribe($this->client($request), $validator->validated());

            return response()->json([
                'success' => true,
                'message' => 'Subscription cancelled successfully.',
                'data'    => [
                    ...$this->customerData($subscription),
                    'status'         => $subscription->status,
                    'cancelled_at'   => $subscription->cancelled_at?->toIso8601ZuluString(),
                    'effective_date' => $subscription->effective_date?->toDateString(),
                ],
            ]);
        });
    }

    public function updateAmount(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            ...self::LOOKUP_RULES,
            'subscription_amount' => self::AMOUNT_RULES,
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        return $this->handle(function () use ($request, $validator) {
            $result       = $this->subscriptions->updateAmount($this->client($request), $validator->validated());
            $subscription = $result['subscription'];

            return response()->json([
                'success' => true,
                'message' => 'Subscription amount updated successfully.',
                'data'    => [
                    ...$this->customerData($subscription),
                    'previous_amount'     => round($result['previous_amount_cents'] / 100, 2),
                    'subscription_amount' => $subscription->amountDecimal(),
                    'billing_cycle'       => $subscription->billing_cycle,
                    'status'              => $subscription->status,
                    // The next payment link (sent on this date) uses the new amount.
                    'effective_from'      => $subscription->next_billing_on?->toDateString(),
                ],
            ]);
        });
    }

    private function handle(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (SubscriptionException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->status());
        } catch (\Throwable $e) {
            Log::error('Subscription API error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unexpected error. Please try again later.',
            ], 500);
        }
    }

    private function customerData(Subscription $subscription): array
    {
        return array_filter([
            'simphub_reference_id' => $subscription->reference_id,
            'customer_email'       => $subscription->customer_email,
            'customer_name'        => $subscription->customer_name,
            'customer_unique_id'   => $subscription->customer_unique_id,
        ], fn ($value) => $value !== null);
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'The given data was invalid.',
            'errors'  => $errors,
        ], 422);
    }

    private function client(Request $request): Client
    {
        return $request->attributes->get('subscription_client');
    }
}
