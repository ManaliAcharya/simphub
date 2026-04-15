<?php

namespace Modules\Payment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PaymentSubmitController extends Controller
{
    public function store(Request $request, string $session): JsonResponse
    {
        return response()->json([
            'session' => $session,
            'message' => 'Payment submission placeholder.',
            'payload' => $request->all(),
        ], 202);
    }
}
