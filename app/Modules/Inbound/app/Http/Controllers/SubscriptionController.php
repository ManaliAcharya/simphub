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
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function subscribe(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_email'      => ['required', 'email', 'max:255'],
            'customer_name'       => ['nullable', 'string', 'max:255'],
            'customer_unique_id'  => ['nullable', 'string', 'max:255'],
            'subscription_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.50', 'max:999999.99'],
            // Only monthly billing is supported for now.
            'billing_cycle'       => ['nullable', 'string', 'in:monthly'],
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
        $validator = Validator::make($request->all(), [
            'simphub_reference_id' => ['nullable', 'string', 'max:32'],
            'customer_unique_id'   => ['nullable', 'string', 'max:255'],
            'customer_email'       => ['required_without_all:simphub_reference_id,customer_unique_id', 'nullable', 'email', 'max:255'],
            'customer_name'        => ['required_without_all:simphub_reference_id,customer_unique_id', 'nullable', 'string', 'max:255'],
        ]);

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
