<?php

namespace App\Support\Integrations\HIBP;

use App\Support\Contracts\Integrations\PasswordBreachCheckerInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PwnedPasswordService implements PasswordBreachCheckerInterface
{
    private const API_URL = 'https://api.pwnedpasswords.com/range/';

    public function isPwned(string $password): bool
    {
        $sha1 = strtoupper(sha1($password));

        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'User-Agent' => 'Payment Middleware Password Check',
                    'Add-Padding' => 'true',
                ])
                ->get(self::API_URL . $prefix);

            if (! $response->successful()) {
                Log::warning('HIBP API request failed.', [
                    'status' => $response->status(),
                ]);

                return false;
            }

            foreach (explode("\n", $response->body()) as $line) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                [$hashSuffix, $count] = array_pad(explode(':', $line, 2), 2, null);

                if ((int) $count === 0) {
                    continue;
                }

                if (hash_equals($suffix, strtoupper($hashSuffix))) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            Log::warning('HIBP API exception.', [
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
