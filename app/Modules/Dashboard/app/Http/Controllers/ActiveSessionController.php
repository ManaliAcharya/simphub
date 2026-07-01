<?php

namespace Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Models\MerchantSession;
use Modules\Auth\Services\MerchantSessionService;
use Modules\Dashboard\DataTables\ActiveSessionsDataTable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ActiveSessionController extends Controller
{
    public function __construct(protected MerchantSessionService $merchantSessionService) {}

    /**
     * Active sessions page
     */
    public function index(ActiveSessionsDataTable $dataTable)
    {

        return $dataTable->render(
            'dashboard::sessions.list'
        );
    }


    /**
     * Revoke single session
     */
    public function revoke(Request $request, MerchantSession $session): JsonResponse
    {
        $clientAccount = $request->attributes->get('client_account');

        abort_if(
            $session->client_account_id !== $clientAccount->id,
            403,
            'Unauthorized session.'
        );

        $this->merchantSessionService->revokeSession(
            $session,
            'user_logout_other_device',
            [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'request_id' => $request->header('X-Request-ID'),
            ]
        );

        return response()->json([
            'message' => 'Session signed out successfully.',
        ]);
    }

    public function logoutOthers(Request $request): JsonResponse
    {
        $currentSession = null;

        try {
            $currentSession = $request->attributes->get('merchant_session');

            if (! $currentSession) {
                return response()->json([
                    'message' => 'Session not found.',
                ], Response::HTTP_UNAUTHORIZED);
            }

            $count = $this->merchantSessionService->revokeOtherSessions(
                clientAccountId: $currentSession->client_account_id,
                currentSessionId: $currentSession->id,
                context: [
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'request_id' => $request->header('X-Request-ID'),
                ]
            );

            return response()->json([
                'message' => $count > 0
                    ? sprintf('%d session(s) signed out successfully.', $count)
                    : 'No other active sessions found.',
                'count' => $count,
            ]);
        } catch (Throwable $exception) {

            Log::error('Failed to logout other sessions.', [
                'session_id' => $currentSession?->id,
                'client_account_id' => $currentSession?->client_account_id,
                'request_id' => $request->header('X-Request-ID'),
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to sign out other sessions. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
