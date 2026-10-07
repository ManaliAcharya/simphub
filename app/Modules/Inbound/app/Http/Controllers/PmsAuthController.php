<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Services\Connectors\LawcusConnector;
use Modules\Inbound\Services\PmsConnectorRegistry;
use Modules\Inbound\Services\PmsOAuthStateService;
use Throwable;
use Modules\Inbound\Models\WaveConnection;
use Modules\Inbound\Services\WaveApiClient;

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

        return redirect()->away($registry->for($provider)->authorizationUrl($client->pms_client_id));
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
