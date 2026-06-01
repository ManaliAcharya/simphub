<?php

namespace Modules\Payment\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Modules\Billing\Models\PaymentSession;
use Modules\Inbound\Models\Client;

class PaymentPageController extends Controller
{
    public function show(string $session): View
    {
        $logoUrl   = null;
        $feeConfig = null;

        $paymentSession = PaymentSession::query()
            ->where('hosted_url_token', $session)
            ->first();

        if ($paymentSession) {
            $invoice = $paymentSession->invoice;
            if ($invoice && $invoice->pms_client_id) {
                $client = Client::query()
                    ->where('pms_client_id', $invoice->pms_client_id)
                    ->first();

                if ($client) {
                    $logoUrl = $client->logo_path ? '/storage/' . $client->logo_path : null;

                    $feeConfig = [
                        'cc_fee_percent'        => (float) ($client->cc_fee_percent ?? 0),
                        'ach_fee_percent'        => (float) ($client->ach_fee_percent ?? 0),
                        'fee_surcharge_enabled'  => (bool) $client->fee_surcharge_enabled,
                    ];
                }
            }
        }

        return view('payment::checkout', [
            'sessionToken' => $session,
            'logoUrl'      => $logoUrl,
            'feeConfig'    => $feeConfig,
        ]);
    }
}
