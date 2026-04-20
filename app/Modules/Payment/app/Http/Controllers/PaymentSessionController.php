<?php

namespace Modules\Payment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Billing\Models\PaymentSession;
use Modules\Payment\Services\PaymentCheckoutService;
use RuntimeException;

class PaymentSessionController extends Controller
{
    public function show(string $session, PaymentCheckoutService $checkout): JsonResponse
    {
        $paymentSession = PaymentSession::query()
            ->where('hosted_url_token', $session)
            ->firstOrFail();

        try {
            return response()->json($checkout->details($paymentSession));
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

}
