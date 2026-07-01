@extends('auth::emails.layouts.layout')

@section('email_content')
    <div style="font-size:20px; font-weight:600; padding-bottom:20px;">
        Account Suspended
    </div>

    <div style="padding-bottom:20px;">
        Your Payment Middleware merchant account has been suspended after multiple account lockouts within a 24-hour period.
    </div>

    <div style="background:#f8fafc; border:1px solid #e4e6ef; border-radius:8px; padding:18px; margin-bottom:20px;">

        <div style="padding-bottom:6px;">
            <strong>Merchant:</strong> {{ $clientName ?? '' }}
        </div>

        <div style="padding-bottom:6px;">
            <strong>Email:</strong> {{ $email ?? '' }}
        </div>

        <div style="padding-bottom:6px;">
            <strong>Reason:</strong> Multiple account lockouts detected
        </div>

        <div>
            <strong>Suspended At:</strong> {{ $suspendedAt ?? '' }}
        </div>

    </div>

    <div style="padding-bottom:20px;">
        Your account is suspended pending administrator review and all active sessions may be terminated as a security
        precaution.
    </div>

    <div style="padding-bottom:20px;">
        Please contact support to request re-activation of your account.
    </div>

    <div>
        If you believe this action was taken in error or you did not perform these login attempts, contact support
        immediately.
    </div>
@endsection
