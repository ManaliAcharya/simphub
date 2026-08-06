{{-- app/Modules/Auth/resources/views/forgot-password.blade.php --}}

@extends('auth::layouts.auth')

@section('title', 'Forgot Password')

@section('content')
<div class="d-flex flex-column flex-root min-vh-100">
    <div class="d-flex flex-column flex-center flex-column-fluid p-5">
        <div class="bg-white auth-card w-100 p-10 p-lg-15">

            <div class="text-center mb-10">
                <div class="mb-5">
                    <img src="{{ asset('images/logo/simphub-logo.jpeg') }}" alt="SimpHub" style="height: 64px; border-radius: 8px;">
                </div>

                <div class="mb-5">
                    <span class="auth-badge">
                        Password Recovery
                    </span>
                </div>

                <h1 class="text-dark fw-bolder mb-3">
                    Reset Your Password
                </h1>

                <div class="text-gray-500 fw-semibold fs-6">
                    Enter your email address to receive a verification code.
                </div>
            </div>

            @if ($errors->any())
                <div class="alert alert-danger d-flex align-items-center p-5 mb-8">
                    <div class="fw-semibold">
                        {{ $errors->first() }}
                    </div>
                </div>
            @endif

            <div id="formAlert" class="alert d-none mb-8"></div>

            <form id="forgotPasswordForm" novalidate="novalidate">
                @csrf

                {{-- Step 1: Email --}}
                <div id="emailStep">
                    <div class="fv-row mb-7">
                        <label class="form-label fw-bold text-dark">
                            Email Address
                        </label>

                        <input type="email"
                               name="email"
                               id="email"
                               placeholder="client@example.com"
                               autocomplete="email"
                               class="form-control form-control-lg bg-transparent">
                    </div>

                    <div class="d-grid mb-8">
                        <button type="button" id="sendCodeBtn" class="btn btn-lg btn-primary fw-bold">
                            <span class="indicator-label">
                                Send Reset Code
                            </span>

                            <span class="indicator-progress">
                                Please wait...
                                <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                            </span>
                        </button>
                    </div>
                </div>

                {{-- Step 2: Code --}}
                <div id="codeStep" class="d-none">

                    <div class="fv-row mb-7">
                        <label class="form-label fw-bold text-dark">
                            Verification Code
                        </label>

                        <input type="text"
                               name="code"
                               id="code"
                               maxlength="6"
                               inputmode="numeric"
                               placeholder="Enter 6-digit code"
                               autocomplete="one-time-code"
                               class="form-control form-control-lg bg-transparent">
                    </div>

                    <div class="d-grid mb-5">
                        <button type="button" id="verifyCodeBtn" class="btn btn-lg btn-primary fw-bold">
                            <span class="indicator-label">
                                Verify Code
                            </span>

                            <span class="indicator-progress">
                                Please wait...
                                <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                            </span>
                        </button>
                    </div>

                    <div class="text-center mb-8">
                        <button type="button" id="changeEmailBtn" class="btn btn-link fw-semibold">
                            Use a different email
                        </button>
                    </div>
                </div>

                {{-- Step 3: Password --}}
                <div id="passwordStep" class="d-none">
                    <div class="fv-row mb-7">
                        <label class="form-label fw-bold text-dark">
                            New Password
                        </label>

                        <input type="password"
                               name="password"
                               id="password"
                               placeholder="Minimum 12 characters"
                               autocomplete="new-password"
                               class="form-control form-control-lg bg-transparent">
                    </div>

                    <div class="fv-row mb-8">
                        <label class="form-label fw-bold text-dark">
                            Confirm Password
                        </label>

                        <input type="password"
                               name="password_confirmation"
                               id="password_confirmation"
                               placeholder="Re-enter password"
                               autocomplete="new-password"
                               class="form-control form-control-lg bg-transparent">
                    </div>

                    <div class="d-grid mb-8">
                        <button type="button" id="resetPasswordBtn" class="btn btn-lg btn-primary fw-bold">
                            <span class="indicator-label">
                                Reset Password
                            </span>

                            <span class="indicator-progress">
                                Please wait...
                                <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                            </span>
                        </button>
                    </div>
                </div>
            </form>

            <!-- <div class="security-notice p-6">
                <div class="fw-bold text-dark mb-1">
                    Secure Password Reset
                </div>

                <div class="fw-semibold text-gray-700">
                    Password reset codes are single-use and expire shortly. If you did not request a reset, you can safely ignore the email.
                </div>
            </div> -->

            <div class="text-center mt-8">
                <a href="{{ route('auth.login') }}" class="link-primary fw-semibold">
                    Back to Login
                </a>
            </div>

        </div>
    </div>
</div>
@endsection

@push('scripts')
    <script>
        window.forgotPasswordRoutes = {
            requestCode: "{{ route('auth.forgot-password.request-code') }}",
            verifyCode: "{{ route('auth.forgot-password.verify-code') }}",
            resetPassword: "{{ route('auth.forgot-password.reset') }}",
            login: "{{ route('auth.login') }}"
        };

        console.log('Forgot Password Routes:', window.forgotPasswordRoutes);
    </script>

    <script src="{{ asset('assets/js/auth/forgot-password.js?v=' . time()) }}"></script>
@endpush
