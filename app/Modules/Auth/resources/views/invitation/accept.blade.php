{{-- app/Modules/Auth/resources/views/invitation/accept.blade.php --}}

@extends('auth::layouts.auth')

@section('title', 'Complete Account Setup')

@section('content')
<div class="d-flex flex-column flex-root min-vh-100">

    <div class="d-flex flex-column flex-center flex-column-fluid p-5">
        <div class="bg-white auth-card w-100 p-10 p-lg-15">

            <div class="text-center mb-10">
                <div class="mb-5">
                    <span class="auth-badge">
                        Merchant Account Setup
                    </span>
                </div>

                <h1 class="text-dark fw-bolder mb-3">
                    Set Up Your Password
                </h1>

                <div class="text-gray-500 fw-semibold fs-6">
                    Create a password for
                </div>

                <div class="text-primary fw-bold fs-5 mt-2 text-break">
                    {{ $email }}
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
            <form id="resetPasswordForm" method="POST" action="{{ route('auth.invitation.store') }}" novalidate="novalidate">
                @csrf

                <input type="hidden" name="token" value="{{ $token }}">

                <div class="fv-row mb-7">
                    <label class="form-label fw-bold text-dark">
                        Password
                    </label>

                    <input type="password" name="password" placeholder="Minimum 12 characters"
                        class="form-control form-control-lg bg-transparent">
                </div>

                <div class="fv-row mb-8">
                    <label class="form-label fw-bold text-dark">
                        Confirm Password
                    </label>

                    <input type="password" name="password_confirmation" placeholder="Re-enter password"
                        class="form-control form-control-lg bg-transparent">
                </div>

                <div class="d-grid mb-8">
                    <button type="submit" id="submitBtn" class="btn btn-lg btn-primary fw-bold">
                        <span class="indicator-label">
                            Create Password & Continue
                        </span>

                        <span class="indicator-progress">
                            Please wait...
                            <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                        </span>
                    </button>
                </div>
            </form>

            <div class="security-notice p-6">
                <div class="fw-bold text-dark mb-1">
                    Secure Invitation
                </div>

                <div class="fw-semibold text-gray-700">
                    This link is single-use and expires in 7 days. Your password will be securely hashed before storage.
                </div>
            </div>

        </div>
    </div>
</div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/auth/invitation-accept.js?v=' . time()) }}"></script>
@endpush
