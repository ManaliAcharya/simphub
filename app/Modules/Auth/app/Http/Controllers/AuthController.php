<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Services\AuthService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        protected AuthService $authService
    ) {}

    public function index(Request $request): View
    {
        return view('auth::login', [
            'requireCaptcha' => $this->authService->shouldShowCaptcha($request->ip()),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],
                'cf-turnstile-response' => ['nullable', 'string'],
            ]);

            $result = $this->authService->login([
                ...$validated,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $account = $result['account'];
            $client = $account->client;

            $redirectUrl = session()->pull(
                'url.intended',
                clientConfigUrl($client)
            );

            return response()
                ->json([
                    'success' => true,
                    'message' => 'Login successful.',
                    'redirect_url' => $redirectUrl,
                ])
                ->withCookie(cookie(
                    name: 'merchant_session',
                    value: $result['raw_session_token'],
                    minutes: config('auth.merchant_session_lifetime', 720),
                    path: '/',
                    domain: config('session.domain'),
                    secure: config('session.secure', app()->environment('production')),
                    httpOnly: true,
                    raw: false,
                    sameSite: 'Strict'
                ));
        } catch (ValidationException $e) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                status: 422,
                request: $request,
                errors: $e->errors()
            );
        } catch (HttpException $e) {
            return $this->errorResponse(
                message: $e->getMessage() ?: 'Authentication failed.',
                status: $e->getStatusCode(),
                request: $request
            );
        } catch (Throwable $e) {
            Log::error('Unexpected merchant login error.', [
                'email' => $request->input('email'),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                message: 'Something went wrong. Please try again.',
                status: 500,
                request: $request
            );
        }
    }

    private function errorResponse(
        string $message,
        int $status,
        Request $request,
        array $errors = []
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'require_captcha' => $this->authService->shouldShowCaptcha($request->ip()),
        ], $status);
    }

    public function logout(Request $request)
    {
        $rawSessionToken = $request->cookie('merchant_session');

        if ($rawSessionToken) {
            $this->authService->logout(
                rawSessionToken: $rawSessionToken,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
                requestId: $request->attributes->get('request_id')
            );
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('auth.login')
            ->withoutCookie('merchant_session');
    }
}
