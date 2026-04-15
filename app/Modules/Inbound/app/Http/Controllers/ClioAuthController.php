<?php

namespace Modules\Inbound\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inbound\Services\ClioOAuthService;
use Modules\Inbound\Services\ClioWebhookService;
use Throwable;

class ClioAuthController extends Controller
{
    public function redirect(ClioOAuthService $oauth): RedirectResponse
    {
        return redirect()->away($oauth->authorizationUrl());
    }

    public function callback(
        Request $request,
        ClioOAuthService $oauth,
        ClioWebhookService $webhooks,
    ): RedirectResponse {
        if ($request->filled('error')) {
            return redirect()->route('inbound.clio.page', [
                'error' => $request->string('error_description')->toString() ?: $request->string('error')->toString(),
            ]);
        }

        try {
            $oauth->validateState($request->query('state'));
            $connection = $oauth->exchangeCode((string) $request->query('code'));
            $webhook = $webhooks->registerInvoiceCreatedWebhook($connection);

            return redirect()->route('inbound.clio.page', [
                'success' => 'Clio connected and webhook registered.',
                'webhook_id' => $webhook['id'] ?? null,
            ]);
        } catch (Throwable $exception) {
            return redirect()->route('inbound.clio.page', [
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
