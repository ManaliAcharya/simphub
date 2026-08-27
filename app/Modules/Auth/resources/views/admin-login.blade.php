{{-- app/Modules/Auth/resources/views/admin-login.blade.php --}}

@extends('auth::layouts.auth')

@section('title', 'Admin Login')

@section('content')
    <div class="d-flex flex-column flex-root min-vh-100" id="kt_app_root">

        <div class="d-flex flex-column flex-center flex-column-fluid p-5">
            <div class="bg-white auth-card w-100 p-10 p-lg-15">

                <div class="text-center mb-10">
                    <div class="mb-5">
                        <img src="{{ asset('images/logo/simphub-logo.jpeg') }}" alt="SimpHub" style="height: 64px; border-radius: 8px;">
                    </div>

                    <div class="mb-5">
                        <span class="auth-badge">
                            Admin
                        </span>
                    </div>

                    <h1 class="text-dark fw-bolder mb-3">
                        Sign In
                    </h1>
                </div>

                @if ($errors->any())
                    <div class="alert alert-danger">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.login.submit') }}" novalidate="novalidate">
                    @csrf

                    <div class="fv-row mb-7">
                        <label for="email" class="form-label fw-bold text-dark">
                            Email Address
                        </label>

                        <input type="email" name="email" id="email" value="{{ old('email') }}" placeholder="admin@example.com"
                            autocomplete="email" class="form-control form-control-lg bg-transparent" required autofocus>
                    </div>

                    <div class="fv-row mb-5">
                        <label for="password" class="form-label fw-bold text-dark">
                            Password
                        </label>

                        <input type="password" name="password" id="password" placeholder="Enter your password"
                            autocomplete="current-password" class="form-control form-control-lg bg-transparent" required>
                    </div>

                    <div class="d-grid mt-8 mb-8">
                        <button type="submit" class="btn btn-lg btn-primary fw-bold">
                            Sign In
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>
@endsection
