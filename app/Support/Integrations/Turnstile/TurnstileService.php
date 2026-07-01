<?php

namespace App\Support\Integrations\Turnstile;

use App\Support\Contracts\Integrations\CaptchaVerifierInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TurnstileService implements CaptchaVerifierInterface
{
    public function verify(string $token, ?string $ipAddress = null): bool
    {
        try {
            $response = Http::asForm()
                ->timeout(8)
                ->post(config('turnstile.verify_url'), [
                    'secret' => config('turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $ipAddress,
                ]);

            if (! $response->successful()) {
                Log::warning('Turnstile verification request failed.', [
                    'status' => $response->status(),
                ]);

                return false;
            }

            return (bool) data_get($response->json(), 'success', false);
        } catch (Throwable $e) {
            Log::warning('Turnstile verification exception.', [
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
