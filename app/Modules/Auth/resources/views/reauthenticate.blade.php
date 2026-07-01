@extends('layouts.app')

@section('content')
<div class="d-flex flex-column flex-root">
    <div class="d-flex flex-column flex-center flex-column-fluid p-10">

        <div class="card shadow-sm w-100 mw-450px">
            <div class="card-body p-10">

                <div class="text-center mb-10">
                    <h1 class="fw-bold mb-3">Confirm Password</h1>
                    <div class="text-muted fw-semibold fs-6">
                        For security, please re-enter your password.
                    </div>
                </div>

                <div class="notice d-flex bg-light-primary rounded border-primary border border-dashed p-4 mb-8">
                    <i class="ki-duotone ki-shield-tick fs-2tx text-primary me-4">
                        <span class="path1"></span>
                        <span class="path2"></span>
                    </i>

                    <div class="fs-7 text-gray-700">
                        This confirmation is required before continuing to a sensitive action.
                    </div>
                </div>

                <form method="POST" action="{{ route('auth.reauthenticate') }}">
                    @csrf

                    <div class="fv-row mb-8">
                        <label class="required form-label">Current Password</label>

                        <input type="password"
                               name="password"
                               class="form-control form-control-lg form-control-solid @error('password') is-invalid @enderror"
                               autocomplete="current-password"
                               autofocus>

                        @error('password')
                            <div class="invalid-feedback d-block">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        Continue
                    </button>
                </form>

            </div>
        </div>

    </div>
</div>
@endsection
