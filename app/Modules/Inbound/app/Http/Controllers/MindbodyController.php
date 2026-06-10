<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\MindbodySite;
use Modules\Inbound\Services\MindbodyApiClient;
use Modules\Inbound\Services\MindbodyAuthService;
use Modules\Inbound\Services\MindbodyWebhookSubscriptionService;

class MindbodyController extends Controller
{
    public function __construct(
        private readonly MindbodyApiClient $api,
        private readonly MindbodyAuthService $auth,
        private readonly MindbodyWebhookSubscriptionService $webhooks,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $pmsClientId = (string) $request->query('pms_client_id', '');

        $client = $pmsClientId
            ? Client::query()->where('pms_client_id', $pmsClientId)->first()
            : null;

        $site = $client
            ? MindbodySite::query()->where('pms_client_id', $client->pms_client_id)->first()
            : null;

        $customPaymentMethods = [];
        $loadError = null;

        if ($site) {
            try {
                $site = $this->auth->ensureValidToken($site);
                $customPaymentMethods = $this->api->fetchCustomPaymentMethods($site);
            } catch (\Throwable $e) {
                $loadError = $e->getMessage();
            }
        }

        return view('inbound::mindbody.page', [
            'client'               => $client,
            'site'                 => $site,
            'customPaymentMethods' => $customPaymentMethods,
            'loadError'            => $loadError,
            'success'              => $request->query('success'),
            'error'                => $request->query('error') ?? $loadError,
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pms_client_id'    => ['required', 'string'],
            'site_id'          => ['required', 'string', 'max:20'],
            'staff_username'   => ['required', 'string', 'max:255'],
            'staff_password'   => ['required', 'string', 'max:255'],
            'site_name'        => ['nullable', 'string', 'max:255'],
        ]);

        $client = Client::query()
            ->where('pms_client_id', $validated['pms_client_id'])
            ->firstOrFail();

        try {
            // Test credentials first
            $tokenData = $this->auth->testCredentials(
                $validated['site_id'],
                $validated['staff_username'],
                $validated['staff_password'],
            );

            // Upsert site record
            $site = MindbodySite::query()->updateOrCreate(
                ['pms_client_id' => $client->pms_client_id, 'site_id' => $validated['site_id']],
                [
                    'site_name'                => $validated['site_name'] ?? null,
                    'staff_username_encrypted' => $validated['staff_username'],
                    'staff_password_encrypted' => $validated['staff_password'],
                    'staff_token'              => $tokenData['AccessToken'] ?? null,
                    'staff_token_expires_at'   => isset($tokenData['ExpiresIn'])
                        ? now()->addSeconds((int) $tokenData['ExpiresIn'])
                        : null,
                    'is_active'                => true,
                    'last_error'               => null,
                ],
            );

            // Create + activate webhook subscription
            $this->webhooks->createAndActivate($site);

            return redirect()->route('inbound.mindbody.page', [
                'pms_client_id' => $client->pms_client_id,
                'success'       => 'Mindbody connected successfully. Webhooks are active.',
            ]);
        } catch (\Throwable $e) {
            return redirect()->route('inbound.mindbody.page', [
                'pms_client_id' => $client->pms_client_id,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pms_client_id'             => ['required', 'string'],
            'custom_payment_method_id'  => ['required', 'integer'],
            'custom_payment_method_name'=> ['nullable', 'string', 'max:255'],
            'payment_link_mode'         => ['required', 'string', 'in:per_sale,account_balance'],
        ]);

        $site = MindbodySite::query()
            ->where('pms_client_id', $validated['pms_client_id'])
            ->firstOrFail();

        $site->forceFill([
            'custom_payment_method_id'   => $validated['custom_payment_method_id'],
            'custom_payment_method_name' => $validated['custom_payment_method_name'] ?? null,
            'payment_link_mode'          => $validated['payment_link_mode'],
        ])->save();

        return redirect()->route('inbound.mindbody.page', [
            'pms_client_id' => $validated['pms_client_id'],
            'success'       => 'Mindbody settings saved.',
        ]);
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $pmsClientId = $request->input('pms_client_id', '');

        $site = MindbodySite::query()
            ->where('pms_client_id', $pmsClientId)
            ->first();

        if ($site) {
            try {
                $this->webhooks->delete($site);
            } catch (\Throwable) {}

            $site->forceFill(['is_active' => false])->save();
        }

        return redirect()->route('inbound.mindbody.page', [
            'pms_client_id' => $pmsClientId,
            'success'       => 'Mindbody disconnected.',
        ]);
    }
}
