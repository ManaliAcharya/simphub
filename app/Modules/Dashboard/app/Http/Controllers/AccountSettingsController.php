<?php

namespace Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Enums\AuditEventType;
use Modules\Auth\Enums\OperationOutcome;
use Modules\Auth\Repositories\ClientAccountRepository;
use Modules\Auth\Rules\SecurePassword;
use Modules\Auth\Services\AuthAuditService;
use Illuminate\Support\Str;

class AccountSettingsController extends Controller
{
    protected ClientAccountRepository $clientAccountRepository;
    protected AuthAuditService $authAuditService;
    public function __construct(ClientAccountRepository $clientAccountRepository, AuthAuditService $authAuditService)
    {
        $this->clientAccountRepository = $clientAccountRepository;
        $this->authAuditService = $authAuditService;
    }
    public function index(Request $request)
    {

        return view('dashboard::account.settings', [
            'account' => $request->attributes->get('client_account'),
        ]);
    }

    public function updateEmail(Request $request): JsonResponse
    {
        $account = $request->attributes->get('client_account');
        $validated = $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:client_accounts,email,' . $account->id,
            ],
            'current_password' => ['required', 'string'],
        ]);

        if (! Hash::check($validated['current_password'], $account->password_hash)) {

            $this->authAuditService->log([
                'event_type' => AuditEventType::EMAIL_CHANGE_FAILED->value,
                'client_account_id' => $account->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'request_id' => $request->header('X-Request-ID'),
                'outcome' => OperationOutcome::FAILURE->value,
                'failed_reason' => 'invalid_current_password',
                'metadata' => [
                    'attempted_email' => Str::lower(trim($validated['email'])),
                ],
            ]);

            return response()->json([
                'message' => 'Current password is incorrect.',
                'errors' => [
                    'current_password' => [
                        'Current password is incorrect.'
                    ],
                ],
            ], 422);
        }


        $oldEmail = $account->email;

        $this->clientAccountRepository->update($account, [
            'email' => $validated['email'],
            'email_lower' => Str::lower(trim($validated['email']))
        ]);

        $this->authAuditService->log([
            'event_type' => AuditEventType::EMAIL_CHANGED->value,
            'client_account_id' => $account->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => $request->header('X-Request-ID'),
            'outcome' => OperationOutcome::SUCCESS->value,
            'metadata' => [
                'old_email' => $oldEmail,
                'new_email' => Str::lower(trim($validated['email'])),
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Email updated successfully.',
            'email' => $validated['email'],
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $account = $request->attributes->get('client_account');

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'confirmed',
                new SecurePassword(
                    email: $account?->email ?? '',
                    companyName: $account?->client?->client_name ?? null
                ),
            ],
        ]);

        if (! Hash::check($validated['current_password'], $account->password_hash)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
                'errors' => [
                    'current_password' => ['Current password is incorrect.'],
                ],
            ], 422);
        }

        $this->clientAccountRepository->update($account, [
            'password_hash' => Hash::make($validated['password']),
            'password_set_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully.',
        ]);
    }
}
