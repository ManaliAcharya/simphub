{{-- app/Modules/Auth/resources/views/login.blade.php --}}

@extends('auth::layouts.auth')

@section('title', 'Client Login')

@section('content')
    <div class="d-flex flex-column flex-root min-vh-100" id="kt_app_root">

        <div class="d-flex flex-column flex-center flex-column-fluid p-5">
            <div class="bg-white auth-card w-100 p-10 p-lg-15">

                <div class="text-center mb-10">
                    <div class="mb-5">
                        <span class="auth-badge">
                            Client Payment Middlware
                        </span>
                    </div>

                    <h1 class="text-dark fw-bolder mb-3">
                        Sign In
                    </h1>

                    <div class="text-gray-500 fw-semibold fs-6">
                        Access your Payment Middleware dashboard.
                    </div>
                </div>

                <form method="POST" action="{{ route('auth.login.submit') }}" id="loginForm" novalidate="novalidate">
                    @csrf

                    <div class="fv-row mb-7">
                        <label for="email" class="form-label fw-bold text-dark">
                            Email Address
                        </label>

                        <input type="email" name="email" value="{{ old('email') }}" placeholder="client@example.com"
                            autocomplete="email" class="form-control form-control-lg bg-transparent">
                    </div>

                    <div class="fv-row mb-5">
                        <label for="password" class="form-label fw-bold text-dark">
                            Password
                        </label>

                        <input type="password" name="password" placeholder="Enter your password"
                            autocomplete="current-password" class="form-control form-control-lg bg-transparent">
                    </div>

                    <div class="d-flex flex-stack flex-wrap gap-3 fs-base fw-semibold mb-8">
                        <div></div>

                        <a href="{{ route('auth.forgot-password.index') }}" class="link-primary">
                            Forgot Password?
                        </a>
                    </div>

                    <div id="captchaWrapper" class="fv-row mt-2 mb-7 d-none">

                        <div class="captcha-box">
                            <div id="turnstileContainer"></div>
                        </div>

                        <div id="captchaError" class="text-danger fw-semibold fs-7 mt-2 text-center d-none">
                        </div>

                    </div>

                    <div class="d-grid mt-8 mb-8">
                        <button type="submit" id="loginSubmitBtn" class="btn btn-lg btn-primary fw-bold">
                            <span class="indicator-label">Sign In</span>
                            <span class="indicator-progress">
                                Please wait...
                                <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                            </span>
                        </button>
                    </div>
                </form>

                <div class="security-notice p-6">
                    <div class="fw-bold text-dark mb-1">
                        Secure Access
                    </div>

                    <div class="fw-semibold text-gray-700">
                        Sign in to manage your merchant account and payment integrations.
                    </div>
                </div>

            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        window.turnstileSiteKey = "{{ config('turnstile.site_key') }}";
        window.requireCaptchaOnLoad = @json(!empty($requireCaptcha));
    </script>

    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>
    <script src="{{ asset('assets/js/auth/login.js?v=' . time()) }}"></script>
@endpush
