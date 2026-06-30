<?php

namespace Modules\Auth\Support\RateLimiting;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AuthRateLimiters
{
    public static function register(): void
    {
        self::global();
        self::login();
        self::forgotPasswordRequest();
        self::forgotPasswordVerify();
        self::forgotPasswordReset();
    }

    private static function global(): void
    {
        RateLimiter::for('global', function (Request $request) {
            return self::limit(
                key: 'global',
                request: $request,
                configKey: 'global',
                message: 'Too many requests. Please try again later.'
            );
        });
    }

    private static function login(): void
    {
        RateLimiter::for('login', function (Request $request) {
            return self::limit(
                key: 'login',
                request: $request,
                configKey: 'auth.login',
                message: 'Too many login attempts. Please try again later.'
            );
        });
    }

    private static function forgotPasswordRequest(): void
    {
        RateLimiter::for('forgot-password-request', function (Request $request) {
            return self::limit(
                key: 'forgot-password-request',
                request: $request,
                configKey: 'auth.forgot_password.request',
                message: 'Too many password reset requests. Please try again later.'
            );
        });
    }

    private static function forgotPasswordVerify(): void
    {
        RateLimiter::for('forgot-password-verify', function (Request $request) {
            return self::limit(
                key: 'forgot-password-verify',
                request: $request,
                configKey: 'auth.forgot_password.verify',
                message: 'Too many verification attempts. Please try again later.'
            );
        });
    }

    private static function forgotPasswordReset(): void
    {
        RateLimiter::for('forgot-password-reset', function (Request $request) {
            return self::limit(
                key: 'forgot-password-reset',
                request: $request,
                configKey: 'auth.forgot_password.reset',
                message: 'Too many password reset attempts. Please try again later.'
            );
        });
    }

    private static function limit(
        string $key,
        Request $request,
        string $configKey,
        string $message
    ): Limit {
        $config = config("rate_limits.{$configKey}");

        if (! is_array($config)) {
            throw new \InvalidArgumentException(
                "Rate limit config [rate_limits.{$configKey}] is missing or invalid."
            );
        }

        return Limit::perMinutes(
            max(1, (int) ceil($config['decay_seconds'] / 60)),
            (int) $config['max_attempts']
        )
            ->by($key . ':' . $request->ip())
            ->response(fn() => response()->json([
                'success' => false,
                'message' => $message,
            ], 429));
    }
}
