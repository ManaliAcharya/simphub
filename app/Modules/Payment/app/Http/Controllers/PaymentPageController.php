<?php

namespace Modules\Payment\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;

class PaymentPageController extends Controller
{
    public function show(string $session): View
    {
        return view('payment::checkout', [
            'sessionToken' => $session,
        ]);
    }
}
