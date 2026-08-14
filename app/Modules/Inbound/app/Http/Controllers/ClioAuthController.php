<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClioConnection;
use Modules\Inbound\Services\ClioApiClient;
use Modules\Inbound\Services\ClioOAuthService;
use Modules\Inbound\Services\ClioWebhookService;
use Throwable;

class ClioAuthController extends Controller
{
    public function redirect(ClioOAuthService $oauth): RedirectResponse
    {
        $pmsClientId = (string) request()->query('pms_client_id', '');
        $client = Client::query()->where('pms_client_id', $pmsClientId)->first();

        abort_unless($client, 404, 'Unknown PMS client identifier.');

        return redirect()->away($oauth->authorizationUrl($client->pms_client_id));
    }

    public function callback(
        Request $request,
        ClioOAuthService $oauth,
        ClioWebhookService $webhooks,
    ): RedirectResponse {
        $pmsClientId = '';

        try {
            if ($request->filled('state')) {
                $pmsClientId = (string) (($oauth->validateState($request->query('state')))['pms_client_id'] ?? '');
            }
        } catch (Throwable) {
        }

        if ($request->filled('error')) {
            return redirect()->route('inbound.clio.page', [
                'error' => $request->string('error_description')->toString() ?: $request->string('error')->toString(),
                'pms_client_id' => $pmsClientId,
            ]);
        }

        try {
            $state = $oauth->validateState($request->query('state'));
            $connection = $oauth->exchangeCode(
                code: (string) $request->query('code'),
                pmsClientId: (string) ($state['pms_client_id'] ?? '')
            );
            $webhook = $webhooks->registerInvoiceCreatedWebhook($connection);

            return redirect()->route('inbound.clio.page', [
                'success' => 'Clio connected and webhook registered.',
                'webhook_id' => $webhook['id'] ?? null,
                'pms_client_id' => $connection->pms_client_id,
            ]);
        } catch (Throwable $exception) {
            return redirect()->route('inbound.clio.page', [
                'error' => $exception->getMessage(),
                'pms_client_id' => $pmsClientId,
            ]);
        }
    }

    public function disconnect(Request $request, ClioApiClient $api, ClioOAuthService $oauth): RedirectResponse
    {
        $pmsClientId = $request->validate([
            'pms_client_id' => ['required', 'string'],
        ])['pms_client_id'];

        $connection = ClioConnection::query()
            ->where('provider', 'clio')
            ->where('pms_client_id', $pmsClientId)
            ->first();

        if ($connection) {
            if ($connection->webhook_id) {
                try {
                    $api->deleteWebhook($connection, (string) $connection->webhook_id);
                } catch (Throwable $e) {
                    logger()->warning('Clio: failed to delete webhook on disconnect', [
                        'pms_client_id' => $pmsClientId,
                        'webhook_id'    => $connection->webhook_id,
                        'error'         => $e->getMessage(),
                    ]);
                }
            }

            $oauth->revokeToken($connection);

            $connection->delete();
        }

        return redirect()->route('inbound.clio.page', [
            'success'       => 'Clio disconnected. Click "Connect Clio" to reconnect.',
            'pms_client_id' => $pmsClientId,
        ]);
    }
}
