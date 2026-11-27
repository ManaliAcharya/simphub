<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\JobTreadConnection;
use Modules\Inbound\Services\Connectors\JobTreadConnector;
use Modules\Inbound\Services\Connectors\LawcusConnector;
use Modules\Inbound\Services\JobTreadConnectionService;
use Modules\Inbound\Services\PmsConnectorRegistry;
use Modules\Inbound\Services\PmsOAuthStateService;
use Throwable;
use Modules\Inbound\Models\WaveConnection;
use Modules\Inbound\Services\WaveApiClient;
use Modules\Inbound\Services\ZohoDisconnectService;

class PmsAuthController extends Controller
{
    public function __construct(
        private readonly WaveApiClient $waveApi,
    ) {}

    public function redirect(string $provider, PmsConnectorRegistry $registry): RedirectResponse
    {
        $pmsClientId = (string) request()->query('pms_client_id', '');
        $client = Client::query()->where('pms_client_id', $pmsClientId)->first();

        abort_unless($client, 404, 'Unknown PMS client identifier.');

        // e.g. a Zoho CRM client must not run the Zoho Books flow (which registers a webhook).
        if (! $this->clientUsesProvider($client, $provider)) {
            return $this->redirectToClientPage($client, 'This client is set up for a different PMS. Connect it from its own configuration page.');
        }

        try {
            $authorizationUrl = $registry->for($provider)->authorizationUrl($client->pms_client_id);
        } catch (\RuntimeException $e) {
            // e.g. missing OAuth configuration for the provider.
            return redirect()->route("inbound.{$provider}.page", [
                'pms_client_id' => $client->pms_client_id,
                'error'         => $e->getMessage(),
            ]);
        }

        return redirect()->away($authorizationUrl);
    }

    public function callback(
        Request $request,
        string $provider,
        PmsConnectorRegistry $registry,
        PmsOAuthStateService $state,
    ): RedirectResponse {
        $pmsClientId = '';

        try {
            if ($request->filled('state')) {
                $payload = $state->validate($request->query('state'));
                $pmsClientId = (string) ($payload['pms_client_id'] ?? '');
            }
        } catch (Throwable) {
        }

        if ($request->filled('error')) {
            return redirect()->route("inbound.{$provider}.page", [
                'error' => $request->string('error_description')->toString() ?: $request->string('error')->toString(),
                'pms_client_id' => $pmsClientId,
            ]);
        }

        try {
            $payload = $state->validate($request->query('state'));
            abort_unless(($payload['provider'] ?? null) === $provider, 422, 'OAuth state provider mismatch.');

            $client = Client::query()->where('pms_client_id', (string) ($payload['pms_client_id'] ?? ''))->first();
            abort_unless($client && $this->clientUsesProvider($client, $provider), 422, 'This client is set up for a different PMS.');

            $result = $registry->for($provider)->completeAuthorization(
                (string) $request->query('code'),
                (string) ($payload['pms_client_id'] ?? '')
            );

            return redirect()->route("inbound.{$provider}.page", [
                'success' => $result->successMessage,
                'pms_client_id' => $result->connection->pms_client_id,
                ...$result->redirectParameters,
            ]);
        } catch (Throwable $exception) {
            return redirect()->route("inbound.{$provider}.page", [
                'error' => $exception->getMessage(),
                'pms_client_id' => $pmsClientId,
            ]);
        }
    }

    /**
     * Disconnects Zoho Books or Zoho CRM: removes the webhooks and workflow rules SimpHub
     * created in Zoho Books, revokes the token and deletes the connection.
     */
    public function disconnectZoho(Request $request, string $provider, ZohoDisconnectService $zoho): RedirectResponse
    {
        $pmsClientId = $request->validate([
            'pms_client_id' => ['required', 'string'],
        ])['pms_client_id'];

        $label = $provider === 'zohocrm' ? 'Zoho CRM' : 'Zoho Books';
        $result = $zoho->disconnect($provider, $pmsClientId);

        $params = ['pms_client_id' => $pmsClientId];

        if (! $result['disconnected']) {
            $params['success'] = "{$label} was not connected.";
        } elseif ($result['cleanup_error'] !== null) {
            $params['error'] = "{$label} disconnected, but SimpHub couldn't remove its webhooks from Zoho Books ({$result['cleanup_error']}). "
                .'In Zoho Books, go to Settings > Automation and delete the "Payment Middleware" workflow rules and webhooks.';
        } elseif ($result['shared_organization']) {
            $params['success'] = "{$label} disconnected. Its webhooks were kept because another client uses the same Zoho Books organization.";
        } elseif ($provider === 'zoho') {
            $params['success'] = "Zoho Books disconnected, and {$result['workflows_removed']} workflow rule(s) and {$result['webhooks_removed']} webhook(s) were removed from Zoho Books. Click \"Connect\" to reconnect.";
        } else {
            $params['success'] = 'Zoho CRM disconnected. Click "Connect" to reconnect.';
        }

        return redirect()->route("inbound.{$provider}.page", $params);
    }

    /** Same rule as the share links: the route's provider must be the client's PMS. */
    private function clientUsesProvider(Client $client, string $provider): bool
    {
        return strtoupper($provider) === strtoupper((string) $client->client_pms);
    }

    private function redirectToClientPage(Client $client, string $error): RedirectResponse
    {
        $provider = strtolower((string) $client->client_pms);

        if (! Route::has("inbound.{$provider}.page")) {
            abort(422, $error);
        }

        return redirect()->route("inbound.{$provider}.page", [
            'pms_client_id' => $client->pms_client_id,
            'error'         => $error,
        ]);
    }

    /**
     * Lawcus has no usable OAuth app for us — the firm's own owner/admin pastes a
     * personal access token generated from their Lawcus account instead of going
     * through an OAuth redirect. Kept separate from redirect()/callback() above
     * since those are generic OAuth plumbing shared with Clio/Zoho/Wave.
     */
    public function connectLawcusToken(Request $request, LawcusConnector $connector): RedirectResponse
    {
        $validated = $request->validate([
            'pms_client_id' => ['required', 'string'],
            'access_token'  => ['required', 'string'],
        ]);

        try {
            $result = $connector->connectWithToken($validated['access_token'], $validated['pms_client_id']);

            return redirect()->route('inbound.lawcus.page', [
                'success'       => $result->successMessage,
                'pms_client_id' => $result->connection->pms_client_id,
            ]);
        } catch (Throwable $exception) {
            return redirect()->route('inbound.lawcus.page', [
                'error'         => $exception->getMessage(),
                'pms_client_id' => $validated['pms_client_id'],
            ]);
        }
    }

    /**
     * JobTread has no OAuth: the merchant creates a grant key in JobTread (Settings >
     * Integrations > API > Grants) and it's pasted here, with the organization ID when the
     * key covers more than one organization.
     */
    public function connectJobTreadKey(Request $request, JobTreadConnector $connector): RedirectResponse
    {
        $validated = $request->validate([
            'pms_client_id'   => ['required', 'string'],
            'grant_key'       => ['required', 'string', 'max:500'],
            'organization_id' => ['nullable', 'string', 'max:100'],
        ]);

        $client = Client::query()->where('pms_client_id', $validated['pms_client_id'])->firstOrFail();

        if (! $this->clientUsesProvider($client, 'jobtread')) {
            return $this->redirectToClientPage($client, 'This client is set up for a different PMS. Connect it from its own configuration page.');
        }

        try {
            $result = $connector->connectWithGrantKey(
                $validated['grant_key'],
                $client->pms_client_id,
                $validated['organization_id'] ?? null,
            );

            return redirect()->route('inbound.jobtread.page', [
                'success'       => $result->successMessage,
                'pms_client_id' => $client->pms_client_id,
            ]);
        } catch (Throwable $exception) {
            return redirect()->route('inbound.jobtread.page', [
                'error'         => $exception->getMessage(),
                'pms_client_id' => $client->pms_client_id,
            ]);
        }
    }

    public function disconnectJobTread(Request $request, JobTreadConnectionService $connections): RedirectResponse
    {
        $pmsClientId = $request->validate(['pms_client_id' => ['required', 'string']])['pms_client_id'];
        $connection  = JobTreadConnection::forClient($pmsClientId);

        if ($connection) {
            $connections->disconnect($connection);
        }

        return redirect()->route('inbound.jobtread.page', [
            'success'       => 'JobTread disconnected.',
            'pms_client_id' => $pmsClientId,
        ]);
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $pmsClientId = $request->validate([
            'pms_client_id' => ['required', 'string'],
        ])['pms_client_id'];


        $connection = WaveConnection::query()
            ->where('provider', 'wave')
            ->where('pms_client_id', $pmsClientId)
            ->first();

        if ($connection) {
            try {
                $this->waveApi->uninstallApp($connection);
            } catch (Throwable $e) {
                logger()->warning('Wave: failed to uninstall app on disconnect', [
                    'pms_client_id' => $pmsClientId,
                    'error'         => $e->getMessage(),
                ]);
            }

            $connection->delete();
        }

        return redirect()->route('inbound.wave.page', [
            'success'       => 'Wave disconnected. Click "Connect Wave" to reconnect.',
            'pms_client_id' => $pmsClientId,
        ]);
    }
}
