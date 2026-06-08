<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClioConnection;
use Modules\Inbound\Models\LawcusConnection;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Models\QuickBooksConnection;
use Modules\Inbound\Services\ClioApiClient;
use Modules\Inbound\Services\ClioOAuthService;
use Modules\Inbound\Services\LawcusApiClient;
use Modules\Inbound\Services\LawcusOAuthService;
use Modules\Inbound\Services\PmsConnectorRegistry;
use Modules\Inbound\Services\QuickBooksApiClient;
use Modules\Routing\Models\RoutingRule;
use Modules\Inbound\Services\QuickBooksOAuthService;
use Modules\Inbound\Models\WaveConnection;
use Modules\Inbound\Services\WaveApiClient;
use Modules\Inbound\Services\WaveOAuthService;
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

    public function saveClioDefaultBankAccount(
        Request $request,
        ClioOAuthService $clioOAuth,
        ClioApiClient $clioApi,
    ): RedirectResponse {
        $validated = $request->validate([
            'pms_client_id'                => ['required', 'string'],
            'clio_default_bank_account_id' => ['required', 'string', 'max:100'],
        ]);

        $client = Client::query()
            ->where('pms_client_id', $validated['pms_client_id'])
            ->firstOrFail();

        abort_unless(strtoupper((string) $client->client_pms) === 'CLIO', 422, 'Default bank account is only supported for Clio clients.');

        $connection = ClioConnection::query()
            ->where('provider', 'clio')
            ->where('pms_client_id', $client->pms_client_id)
            ->firstOrFail();

        $connection = $clioOAuth->ensureValidAccessToken($connection);
        $accounts = $clioApi->fetchBankAccounts($connection);

        $selected = collect($accounts)
            ->first(fn (array $a): bool => (string) ($a['account_id'] ?? '') === (string) $validated['clio_default_bank_account_id']);

        abort_unless(is_array($selected), 422, 'Selected bank account is invalid. Please choose from the dropdown.');

        $client->forceFill([
            'clio_default_bank_account_id'   => (string) $selected['account_id'],
            'clio_default_bank_account_name' => (string) ($selected['account_name'] ?? ''),
        ])->save();

        return redirect()->route('inbound.clio.page', [
            'pms_client_id' => $client->pms_client_id,
            'success'       => 'Default Clio bank account saved.',
        ]);
    }

    public function saveLawcusDefaultBankAccount(
        Request $request,
        LawcusOAuthService $lawcusOAuth,
        LawcusApiClient $lawcusApi,
    ): RedirectResponse {
        $validated = $request->validate([
            'pms_client_id'                   => ['required', 'string'],
            'lawcus_default_bank_account_id'  => ['required', 'string', 'max:100'],
        ]);

        $client = Client::query()
            ->where('pms_client_id', $validated['pms_client_id'])
            ->firstOrFail();

        abort_unless(strtoupper((string) $client->client_pms) === 'LAWCUS', 422, 'Default bank account is only supported for Lawcus clients.');

        $connection = LawcusConnection::query()
            ->where('provider', 'lawcus')
            ->where('pms_client_id', $client->pms_client_id)
            ->firstOrFail();

        $connection = $lawcusOAuth->ensureValidAccessToken($connection);
        $accounts   = $lawcusApi->fetchBankAccounts($connection);

        $selected = collect($accounts)
            ->first(fn (array $a): bool => (string) ($a['account_id'] ?? '') === (string) $validated['lawcus_default_bank_account_id']);

        abort_unless(is_array($selected), 422, 'Selected bank account is invalid. Please choose from the dropdown.');

        $client->forceFill([
            'lawcus_default_bank_account_id'   => (string) $selected['account_id'],
            'lawcus_default_bank_account_name' => (string) ($selected['account_name'] ?? ''),
        ])->save();

        return redirect()->route('inbound.lawcus.page', [
            'pms_client_id' => $client->pms_client_id,
            'success'       => 'Default Lawcus bank account saved.',
        ]);
    }

    public function saveWaveDefaultAccount(
        Request $request,
        WaveOAuthService $waveOAuth,
        WaveApiClient $waveApi,
    ): RedirectResponse {
        $validated = $request->validate([
            'pms_client_id'          => ['required', 'string'],
            'wave_default_account_id' => ['required', 'string', 'max:100'],
        ]);

        $client = Client::query()
            ->where('pms_client_id', $validated['pms_client_id'])
            ->firstOrFail();

        abort_unless(strtoupper((string) $client->client_pms) === 'WAVE', 422, 'Default account is only supported for Wave clients.');

        $connection = WaveConnection::query()
            ->where('provider', 'wave')
            ->where('pms_client_id', $client->pms_client_id)
            ->latest('created_at')
            ->firstOrFail();

        $connection = $waveOAuth->ensureValidAccessToken($connection);
        $accounts   = $waveApi->fetchPaymentAccounts($connection);

        $selected = collect($accounts)
            ->first(fn (array $a): bool => (string) ($a['account_id'] ?? '') === (string) $validated['wave_default_account_id']);

        abort_unless(is_array($selected), 422, 'Selected account is invalid. Please choose from the dropdown.');

        $client->forceFill([
            'wave_default_account_id'   => (string) $selected['account_id'],
            'wave_default_account_name' => (string) ($selected['account_name'] ?? ''),
        ])->save();

        return redirect()->route('inbound.wave.page', [
            'pms_client_id' => $client->pms_client_id,
            'success'       => 'Default Wave payment account saved.',
        ]);
    }

    public function saveQbDefaultAccount(
        Request $request,
        QuickBooksOAuthService $qbOAuth,
        QuickBooksApiClient $qbApi,
    ): RedirectResponse {
        $validated = $request->validate([
            'pms_client_id'      => ['required', 'string'],
            'qb_default_account_id' => ['required', 'string', 'max:100'],
        ]);

        $client = Client::query()
            ->where('pms_client_id', $validated['pms_client_id'])
            ->firstOrFail();

        abort_unless(strtoupper((string) $client->client_pms) === 'QUICKBOOKS', 422, 'Default deposit account is only supported for QuickBooks clients.');

        $connection = QuickBooksConnection::query()
            ->where('provider', 'quickbooks')
            ->where('pms_client_id', $client->pms_client_id)
            ->firstOrFail();

        $connection = $qbOAuth->ensureValidAccessToken($connection);
        $accounts   = $qbApi->fetchChartOfAccounts($connection);

        $selected = collect($accounts)
            ->first(fn (array $a): bool => (string) ($a['account_id'] ?? '') === (string) $validated['qb_default_account_id']);

        abort_unless(is_array($selected), 422, 'Selected account is invalid. Please choose from the dropdown.');

        $client->forceFill([
            'qb_default_account_id'   => (string) $selected['account_id'],
            'qb_default_account_name' => (string) ($selected['account_name'] ?? ''),
        ])->save();

        return redirect()->route('inbound.quickbooks.page', [
            'pms_client_id' => $client->pms_client_id,
            'success'       => 'Default QuickBooks deposit account saved.',
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

        $availableGateways = RoutingRule::query()
            ->where('is_active', true)
            ->distinct()
            ->orderBy('gateway')
            ->pluck('gateway')
            ->filter()
            ->map(fn ($g) => strtoupper((string) $g))
            ->values()
            ->all();

        return view('inbound::pms-integration', [
            'provider' => $provider,
            'providerLabel' => $connector->label(),
            'client' => $client,
            'clients' => Client::query()->where('client_pms', strtoupper($provider))->latest('created_at')->get(),
            'connection' => $connection,
            'availableGateways' => $availableGateways,
            'success' => request()->query('success'),
            'error' => request()->query('error'),
            'connectUrl' => $client
                ? route("inbound.{$provider}.connect", ['pms_client_id' => $client->pms_client_id])
                : null,
            'shareUrl' => $client?->setup_token
                ? route('inbound.setup.share', ['provider' => $provider, 'token' => $client->setup_token])
                : null,
            'openedViaShareLink' => $openedViaShareLink,
            'showSetupLink'      => ! $openedViaShareLink && session('show_setup_link') === true,
            'callbackUrl' => route("inbound.{$provider}.callback"),
            ...$data,
        ]);
    }
}
