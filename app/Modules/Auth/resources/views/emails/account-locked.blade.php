@extends('auth::emails.layouts.layout')

@section('email_content')
<div style="font-size:20px; font-weight:600; padding-bottom:20px;">
    Account Temporarily Locked
</div>

<div style="padding-bottom:20px;">
    We detected multiple failed sign-in attempts for your Payment Middleware merchant account.
    For your security, your account has been temporarily locked.
</div>

<div style="background:#f8fafc; border:1px solid #e4e6ef; border-radius:8px; padding:18px; margin-bottom:20px;">
    <div style="padding-bottom:6px;">
        <strong>Email:</strong> {{ $email }}
    </div>

    <div style="padding-bottom:6px;">
        <strong>IP Address:</strong> {{ $ipAddress ?? 'Unknown' }}
    </div>

    <div>
        <strong>Time:</strong> {{ $lockedAt }}
    </div>
</div>

<div style="padding-bottom:20px;">
    If this was you, please wait a few minutes and try again.
</div>

<div>
    If this was not you, please contact support immediately.
</div>
@endsection
