@extends('auth::emails.layouts.layout')

@section('email_content')
    <h2 style="margin-top:0; color:#2F3044;">Your password was reset</h2>

    <p>Hello,</p>

    <p>
        Your password was reset at
        <strong>{{ $time->format('Y-m-d H:i:s T') }}</strong>
        from IP address
        <strong>{{ $ip }}</strong>.
    </p>

    <p>
        If this wasn't you, contact support immediately.
    </p>
@endsection
