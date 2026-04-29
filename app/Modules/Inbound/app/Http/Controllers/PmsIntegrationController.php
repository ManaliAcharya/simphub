<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Services\PmsConnectorRegistry;

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
