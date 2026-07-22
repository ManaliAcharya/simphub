<x-inbound::layouts.master title="Active Sessions">

    <x-inbound::client-config-layout :client="$clientAccount->owner" :provider-label="$providerLabel ?? ''" :tabs="[]"
        :show-client-header="false" :breadcrumbs="[
        [
            'label' => 'Clients',
        ],
        [
            'label' => $clientAccount->owner->client_name,
            'url' => clientConfigUrl($clientAccount->owner)
        ],
        [
            'label' => 'Active Sessions'
        ]
    ]">

        @push('styles')
            <link href="{{ asset('metronic/assets/plugins/global/plugins.bundle.css') }}" rel="stylesheet"
                type="text/css" />
            <link href="{{ asset('metronic/assets/css/style.bundle.css') }}" rel="stylesheet" type="text/css" />
            <link href="{{ asset('metronic/assets/plugins/custom/datatables/datatables.bundle.css') }}" rel="stylesheet"
                type="text/css" />
            <link href="{{ asset('custom-theme/assets/css/common.css') }}" rel="stylesheet">
        @endpush

        <div class="d-flex flex-column gap-4">

            <!-- Session Page Listing -->
            <div class="cc-card">

                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <div class="cc-card-title">
                            Session List
                        </div>

                        <div class="cc-card-desc mt-1">
                            Monitor and manage devices currently signed into your account.
                        </div>
                    </div>

                    <button type="button" id="kt_logout_other_sessions" class="button danger cc-submit-btn">

                        Sign Out Other Sessions
                    </button>

                </div>

                <div class="table-responsive">
                    {!! $dataTable->table(['class' => 'table align-middle table-row-dashed fs-6 gy-5']) !!}
                </div>

            </div>

        </div>

        {{-- REAUTH MODAL --}}
        @include('auth::partials.reauth-modal')

        @push('scripts')

            {!! $dataTable->scripts() !!}

            <script src="{{ asset('metronic/assets/plugins/global/plugins.bundle.js') }}"></script>
            <script src="{{ asset('metronic/assets/js/scripts.bundle.js') }}"></script>
            <script src="{{ asset('metronic/assets/plugins/custom/datatables/datatables.bundle.js') }}"></script>
            <script src="{{ asset('assets/js/auth/reauthentication.js') }}"></script>
            <script src="{{ asset('assets/js/dashboard/sessions/list.js') }}?v={{ time() }}"></script>

        @endpush

    </x-inbound::client-config-layout>
</x-inbound::layouts.master>
