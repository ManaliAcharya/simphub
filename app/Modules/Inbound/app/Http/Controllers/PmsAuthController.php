<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Services\PmsConnectorRegistry;
use Modules\Inbound\Services\PmsOAuthStateService;
use Throwable;

class PmsAuthController extends Controller
{
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
}
