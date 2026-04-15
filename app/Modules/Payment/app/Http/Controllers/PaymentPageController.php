<?php

namespace Modules\Payment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class PaymentPageController extends Controller
{
    public function show(string $session): JsonResponse
    {
        return response()->json([
            'session' => $session,
            'message' => 'Payment page placeholder.',
        ]);
    }
}
