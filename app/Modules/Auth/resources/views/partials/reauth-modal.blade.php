<div class="modal fade" id="kt_reauth_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered mw-450px">
        <div class="modal-content border-0 shadow-lg rounded-4">

            <div class="modal-header border-0 pb-0">
                <div>
                    <h2 class="fw-bold mb-1">Confirm Password</h2>
                    <div class="text-muted fs-6">
                        For security, please re-enter your password.
                    </div>
                </div>

                <button type="button"
                    class="btn btn-sm btn-icon btn-light"
                    data-bs-dismiss="modal">
                    <i class="ki-duotone ki-cross fs-2">
                        <span class="path1"></span>
                        <span class="path2"></span>
                    </i>
                </button>
            </div>

            <div class="modal-body pt-8">
                <form id="kt_reauth_form" class="form">
                    @csrf

                    <div class="fv-row mb-8">
                        <label class="required fw-semibold fs-6 mb-2">
                            Current Password
                        </label>

                        <input type="password"
                            name="password"
                            id="kt_reauth_password"
                            class="form-control form-control-lg form-control-solid"
                            placeholder="Enter current password"
                            autocomplete="current-password">
                    </div>

                    <div class="notice d-flex bg-light-primary rounded border-primary border border-dashed p-4 mb-8">
                        <i class="ki-duotone ki-shield-tick fs-2tx text-primary me-4">
                            <span class="path1"></span>
                            <span class="path2"></span>
                        </i>

                        <div class="fs-7 text-gray-700">
                            This confirmation is valid for 5 minutes.
                        </div>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="button"
                            class="btn btn-light me-3"
                            data-bs-dismiss="modal">
                            Cancel
                        </button>

                        <button type="submit" id="kt_reauth_submit" class="btn btn-primary">
                            <span class="indicator-label">Continue</span>
                            <span class="indicator-progress">
                                Verifying...
                                <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                            </span>
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>
