<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Services\PmsConnectorRegistry;
use Modules\Inbound\Services\ZohoApiClient;
use Modules\Inbound\Services\ZohoOAuthService;

class PmsIntegrationController extends Controller
{
    public function show(string $provider, PmsConnectorRegistry $registry): View
    {
        return $this->render(
            $provider,
            (string) request()->query('pms_client_id', ''),
            $registry,
            false,
        );
    }

    public function showByToken(string $provider, string $token, PmsConnectorRegistry $registry): View
    {
        $client = Client::query()
            ->where(function ($query) use ($token): void {
                $query->where('setup_token', $token)
                    ->orWhere('pms_client_id', $token);
            })
            ->firstOrFail();

        abort_unless(strtoupper($provider) === (string) $client->client_pms, 404);

        return $this->render(
            $provider,
            (string) $client->pms_client_id,
            $registry,
            true,
        );
    }

    public function saveZohoDefaultAccount(
        Request $request,
        ZohoOAuthService $zohoOAuth,
        ZohoApiClient $zohoApi,
    ): RedirectResponse
    {
        $validated = $request->validate([
            'pms_client_id' => ['required', 'string'],
            'zoho_default_account_id' => ['required', 'string', 'max:100'],
        ]);

        $client = Client::query()
            ->where('pms_client_id', $validated['pms_client_id'])
            ->firstOrFail();

        abort_unless(strtoupper((string) $client->client_pms) === 'ZOHO', 422, 'Default account is only supported for Zoho clients.');

        $connection = PmsConnection::query()
            ->where('provider', 'zoho')
            ->where('pms_client_id', $client->pms_client_id)
            ->firstOrFail();

        $connection = $zohoOAuth->ensureValidAccessToken($connection);
        $organizationId = (string) data_get($connection->meta, 'default_organization_id', '');
        abort_if($organizationId === '', 422, 'Zoho organization id is missing for this client.');

        $accounts = $zohoApi->fetchPaymentAccounts($connection, $organizationId);
        $selectedAccount = collect($accounts)
            ->first(fn (array $account): bool => (string) ($account['account_id'] ?? '') === (string) $validated['zoho_default_account_id']);

        abort_unless(is_array($selectedAccount), 422, 'Selected account is invalid. Please choose from Zoho account dropdown.');

        $client->forceFill([
            'zoho_default_account_id' => (string) $selectedAccount['account_id'],
            'zoho_default_account_name' => (string) ($selectedAccount['account_name'] ?? ''),
        ])->save();

        return redirect()->route('inbound.zoho.page', [
            'pms_client_id' => $client->pms_client_id,
            'success' => 'Default Zoho deposit account saved.',
        ]);
    }

    private function render(
        string $provider,
        string $pmsClientId,
        PmsConnectorRegistry $registry,
        bool $openedViaShareLink = false
    ): View
    {
        $connector = $registry->for($provider);
        $client = $pmsClientId !== ''
            ? Client::query()->where('pms_client_id', $pmsClientId)->first()
            : null;

        if ($client && (! is_string($client->setup_token) || trim($client->setup_token) === '')) {
            $client->forceFill([
                'setup_token' => strtolower(Str::random(12)),
            ])->save();

            $client = $client->fresh();
        }

        $connection = $connector->connection($pmsClientId);
        $data = $connector->integrationData($client, $connection);

        return view('inbound::pms-integration', [
            'provider' => $provider,
            'providerLabel' => $connector->label(),
            'client' => $client,
            'clients' => Client::query()->where('client_pms', strtoupper($provider))->latest('created_at')->get(),
            'connection' => $connection,
            'success' => request()->query('success'),
            'error' => request()->query('error'),
            'connectUrl' => $client
                ? route("inbound.{$provider}.connect", ['pms_client_id' => $client->pms_client_id])
                : null,
            'shareUrl' => $client?->setup_token
                ? route('inbound.setup.share', ['provider' => $provider, 'token' => $client->setup_token])
                : null,
            'openedViaShareLink' => $openedViaShareLink,
            'callbackUrl' => route("inbound.{$provider}.callback"),
            ...$data,
        ]);
    }
}
