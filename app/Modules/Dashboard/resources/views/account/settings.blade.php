<x-inbound::layouts.master title="Account Settings">
    <x-inbound::client-config-layout :client="$clientAccount->client" :provider-label="$providerLabel ?? ''" :tabs="[]" :show-client-header="false" :breadcrumbs="[
        [
            'label' => 'Clients'
        ],
        [
            'label' => $clientAccount->client->client_name,
            'url' => clientConfigUrl($clientAccount->client)
        ],
        [
            'label' => 'Account Settings'
        ]
    ]">

        @push('styles')
        <link href="{{ asset('metronic/assets/plugins/global/plugins.bundle.css') }}" rel="stylesheet" type="text/css" />

        <link href="{{ asset('metronic/assets/css/style.bundle.css') }}" rel="stylesheet" type="text/css" />
        <link href="{{ asset('custom-theme/assets/css/common.css') }}" rel="stylesheet">
        @endpush

        <div class="d-flex flex-column gap-4">

            <!-- Email Section -->
            <div class="cc-card">

                <div style="display:flex;align-items:center;margin-bottom:6px;">
                    <div class="cc-card-title">Email Address</div>

                    <span class="csc-tip-wrap" style="margin-left:6px;">
                        <span class="csc-tip-icon">i</span>
                        <span class="csc-tip-box">
                            This email is used for login authentication and system notifications. Ensure it remains
                            active and accessible.
                        </span>
                    </span>
                </div>

                <div class="cc-card-desc">
                    Update the email address associated with this account. This will be used for login and
                    communication.
                </div>

                <form method="POST" action="{{ route('account.settings.email.update') }}" id="kt_signin_change_email" class="form">
                    @csrf

                    <div class="cc-info-row">
                        <div class="cc-info-icon">
                            ✉️
                        </div>

                        <div>
                            <div class="cc-info-title">Current Email</div>
                            <div class="cc-info-value">
                                {{ $account->email ?? '-' }}
                            </div>
                        </div>
                    </div>

                    <div class="fv-row cc-field">
                        <label class="cc-label" for="email">New Email</label>

                        <input type="email" id="email" name="email" class="cc-input" value="{{ old('email', $account->email ?? '') }}" placeholder="Enter new email">
                    </div>

                    <div class="fv-row cc-field">
                        <label class="cc-label" for="current_password">Current Password</label>

                        <input type="password" id="current_password" name="current_password" class="cc-input" placeholder="Enter password to confirm">
                    </div>

                    <button type="submit" id="kt_email_submit" class="button primary cc-submit-btn">
                        <span class="indicator-label">Update Email</span>

                        <span class="indicator-progress">
                            Please wait...
                            <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                        </span>
                    </button>

                </form>
            </div>

            <!-- Password Section -->
            <div class="cc-card">

                <div style="display:flex;align-items:center;margin-bottom:6px;">
                    <div class="cc-card-title">Password</div>

                    <span class="csc-tip-wrap" style="margin-left:6px;">
                        <span class="csc-tip-icon">i</span>

                        <span class="csc-tip-box">
                            Use a strong password with at least 12 characters. Update regularly for better account
                            security.
                        </span>
                    </span>
                </div>

                <div class="cc-card-desc">
                    Secure your account by updating your password.
                </div>

                <form method="POST" action="{{ route('account.settings.password.update') }}" id="kt_signin_change_password">
                    @csrf

                    <div class="fv-row cc-field">
                        <label class="cc-label" for="password_current_password">Current Password</label>

                        <input type="password" id="password_current_password" name="current_password" class="cc-input" placeholder="Enter current password">
                    </div>

                    <div class="fv-row cc-field">
                        <label class="cc-label" for="password">New Password</label>

                        <input type="password" id="password" name="password" class="cc-input" placeholder="Enter new password">
                    </div>

                    <div class="fv-row cc-field">
                        <label class="cc-label" for="password_confirmation">Confirm Password</label>

                        <input type="password" id="password_confirmation" name="password_confirmation" class="cc-input" placeholder="Confirm new password">
                    </div>

                    <button type="submit" id="kt_password_submit" class="button primary cc-submit-btn">
                        <span class="indicator-label">Update Password</span>

                        <span class="indicator-progress">
                            Please wait...
                            <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                        </span>
                    </button>

                </form>
            </div>
        </div>

    </x-inbound::client-config-layout>

    @include('auth::partials.reauth-modal')

    @push('scripts')
    <script src="{{ asset('metronic/assets/plugins/global/plugins.bundle.js') }}"></script>
    <script src="{{ asset('metronic/assets/js/scripts.bundle.js') }}"></script>
    <script src="{{ asset('assets/js/auth/reauthentication.js') }}"></script>

    <script src="{{ asset('assets/js/dashboard/account/account-settings.js') }}?v={{ time() }}"></script>
    @endpush

</x-inbound::layouts.master>

