<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Services\SubscriptionHistoryService;
use Modules\Routing\Models\RoutingRule;

class CustomPmsController extends Controller
{
    public function apiDocs(Request $request, SubscriptionHistoryService $history): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
    {
        $client = Client::query()
            ->where('pms_client_id', $request->query('pms_client_id'))
            ->first();

        if (! $client || ! in_array($client->client_pms, ['CUSTOM', 'ZOHOCRM'], true)) {
            return redirect()->route('inbound.clients.create');
        }

        $availableGateways = RoutingRule::query()
            ->where('is_active', true)
            ->distinct()
            ->orderBy('gateway')
            ->pluck('gateway')
            ->filter()
            ->map(fn ($g) => strtoupper((string) $g))
            ->values()
            ->all();

        $isSubscriptionApi = $client->client_pms === 'ZOHOCRM';

        return view('inbound::clients.api-docs', [
            'client'            => $client,
            'baseUrl'           => rtrim(config('app.url'), '/'),
            'availableGateways' => $availableGateways,
            'subscriptions'     => $isSubscriptionApi
                ? $history->paginate($client, $request->query('q'), $request->query('status'))
                : null,
            'subscriptionSummary'  => $isSubscriptionApi ? $history->summary($client) : null,
            'subscriptionStatuses' => $history->statuses(),
        ]);
    }

    /** Standalone subscription API docs for Zoho CRM clients (opened from their config page). */
    public function subscriptionApiDocs(string $pmsClientId): \Illuminate\View\View
    {
        $client = Client::query()
            ->where('pms_client_id', $pmsClientId)
            ->where('client_pms', 'ZOHOCRM')
            ->firstOrFail();

        return view('inbound::clients.subscription-api-docs', [
            'client'  => $client,
            'baseUrl' => rtrim(config('app.url'), '/'),
        ]);
    }
}
