<?php

namespace Modules\Boarding\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Audit\Services\AuditLogger;
use Modules\Boarding\Jobs\DispatchBoardingWebhookJob;
use Modules\Boarding\Models\BoardingLinkEvent;
use Modules\Boarding\Models\BoardingMerchant;

class RedirectController extends Controller
{
    /** GET /sq/{token} */
    public function click(Request $request, string $token): RedirectResponse|Response
    {
        $merchant = BoardingMerchant::where('token', $token)->first();

        if (! $merchant) {
            return response()->view('boarding::errors.link-not-found', [], 404);
        }

        if ($merchant->status === 'revoked') {
            BoardingLinkEvent::create([
                'boarding_merchant_id' => $merchant->id,
                'type'                 => 'revoked_click_attempt',
                'ip_address'           => $request->ip(),
                'user_agent'           => $request->userAgent(),
                'created_at'           => now(),
            ]);

            AuditLogger::log('boarding_link.revoked_click_attempt', 'BoardingMerchant', $merchant->id, [
                'merchant_ref' => $merchant->merchant_ref,
                'agent_ref'    => $merchant->agent_ref,
                'ip_address'   => $request->ip(),
            ]);

            return response()->view('boarding::errors.link-revoked', [], 410);
        }

        BoardingLinkEvent::create([
            'boarding_merchant_id' => $merchant->id,
            'type'                 => 'clicked',
            'ip_address'           => $request->ip(),
            'user_agent'           => $request->userAgent(),
            'created_at'           => now(),
        ]);

        $wasAlreadyClicked = $merchant->status === 'clicked';

        if (! $wasAlreadyClicked) {
            $merchant->forceFill([
                'status'     => 'clicked',
                'clicked_at' => now(),
            ])->save();
        }

        AuditLogger::log('boarding_link.clicked', 'BoardingMerchant', $merchant->id, [
            'merchant_ref' => $merchant->merchant_ref,
            'tier'         => $merchant->tier,
            'ip_address'   => $request->ip(),
        ]);

        if (! $wasAlreadyClicked) {
            DispatchBoardingWebhookJob::dispatch($merchant->id, 'link.clicked');
        }

        $masterLink = $merchant->client?->masterLinkFor($merchant->processor, $merchant->tier);

        if (! $masterLink) {
            return response()->view('boarding::errors.link-not-configured', [], 500);
        }

        return redirect()->away($masterLink->url);
    }
}
