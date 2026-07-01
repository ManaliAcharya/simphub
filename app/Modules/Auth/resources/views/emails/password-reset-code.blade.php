@extends('auth::emails.layouts.layout')

@section('email_content')
    <h2 style="margin-top:0; color:#2F3044;">Password Reset Code</h2>

    <p>Hello,</p>

    <p>Your password reset code is:</p>

    <div style="font-size:28px; font-weight:bold; letter-spacing:6px; color:#009ef7; margin:20px 0;">
        {{ $code }}
    </div>

    <p>
        This code expires at
        <strong>{{ $expiresAt->format('Y-m-d H:i:s T') }}</strong>.
    </p>

    <p>
        If you didn't request this, ignore this email and consider changing your password.
    </p>
@endsection
