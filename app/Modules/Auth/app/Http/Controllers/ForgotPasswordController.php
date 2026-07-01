<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Repositories\ClientAccountRepository;
use Modules\Auth\Rules\SecurePassword;
use Modules\Auth\Services\ForgotPasswordService;
use Throwable;

class ForgotPasswordController extends Controller
{
    public function __construct(
        private readonly ForgotPasswordService $forgotPasswordService,
        private readonly ClientAccountRepository $clientAccountRepository
    ) {}

    public function index()
    {
        return view('auth::forgot-password.forgot-password');
    }

    public function requestCode(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'email'],
            ]);

            $this->forgotPasswordService->requestCode([
                ...$validated,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => "If an account exists, we've sent a code to that email. Enter it below.",
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to process your password reset request. Please try again later.',
            ], 500);
        }
    }

    public function verifyCode(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'email'],
                'code' => ['required', 'digits:6'],
            ]);

            $this->forgotPasswordService->verifyCode([
                ...$validated,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Code verified successfully. Please enter your new password.',
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to verify the reset code. Please try again later.',
            ], 500);
        }
    }

    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $clientAccount = $this->clientAccountRepository->findActiveByEmail($request->email);

            $validated = $request->validate([
                'email' => ['required', 'email'],
                'code' => ['required', 'digits:6'],
                'password' => [
                    'required',
                    'confirmed',
                    new SecurePassword(
                        email: $clientAccount['email'],
                        companyName: $clientAccount?->client?->client_name ?? null
                    ),
                ],
            ]);

            $this->forgotPasswordService->resetPassword([
                ...$validated,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Your password has been reset successfully. Please sign in using your new password.',
                'redirect_url' => route('auth.login'),
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to reset your password. Please try again later.',
            ], 500);
        }
    }
}
