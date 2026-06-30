@extends('auth::emails.layouts.layout')

@section('email_content')
    <div style="font-size:20px; font-weight:600; padding-bottom:20px;">
        Welcome
    </div>

    <div style="padding-bottom:25px;">
        You have been invited to access the Payment Middleware Client Portal.
        To activate your account and create your password, click the button below.
    </div>

    <div style="text-align:center; padding-bottom:30px;">
        <a href="{{ $url }}"
           target="_blank"
           style="
                display:inline-block;
                padding:12px 28px;
                background:#009ef7;
                color:#ffffff;
                text-decoration:none;
                border-radius:8px;
                font-weight:600;
           ">
            Set Up Your Password
        </a>
    </div>

    <div style="padding-bottom:20px;">
        This invitation link will expire in <strong>7 days</strong>.
    </div>

    <div style="border-top:1px solid #eeeeee; margin:25px 0;"></div>

    <div style="font-size:13px; color:#7e8299;">
        If the button above does not work, copy and paste the following URL into your browser:
    </div>

    <div style="padding-top:10px; word-break:break-all;">
        <a href="{{ $url }}"
           target="_blank"
           style="color:#009ef7; text-decoration:none;">
            {{ $url }}
        </a>
    </div>
@endsection
