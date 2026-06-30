@extends('auth::emails.layouts.layout')

@section('email_content')
    <div style="font-size:20px; font-weight:600; padding-bottom:20px;">
        New Sign-In Detected
    </div>

    <div style="padding-bottom:20px;">
        We detected a sign-in to your Payment Middleware merchant account from a device not used recently.
    </div>

    <div style="background:#f8fafc; border:1px solid #e4e6ef; border-radius:8px; padding:18px; margin-bottom:20px;">
        <div style="padding-bottom:8px;">
            <strong>Location:</strong> {{ $location }}
        </div>

        <div style="padding-bottom:8px;">
            <strong>IP Address:</strong> {{ $ipAddress }}
        </div>

        <div>
            <strong>Time:</strong> {{ $loggedInAt }}
        </div>
    </div>

    <div style="padding-bottom:20px;">
        If this was you, no action is needed.
    </div>

    <div>
        If this was not you, please change your password immediately or contact support.
    </div>
@endsection
