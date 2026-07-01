"use strict";

const KTReauthentication = function () {
    let modal;
    let modalElement;
    let form;
    let submitButton;
    let validator;
    let pendingRequest = null;
    let isReauthInProgress = false;
    let isRetryingOriginalRequest = false;

    const getCsrfToken = function () {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
    };

    const resetForm = function () {
        form.reset();

        if (validator) {
            validator.resetForm(true);
        }
    };

    const openModal = function () {
        resetForm();

        isReauthInProgress = true;
        isRetryingOriginalRequest = false;

        modal.show();

        setTimeout(function () {
            document.querySelector("#kt_reauth_password")?.focus();
        }, 300);
    };

    const cancelPendingRequest = function () {
        if (!isReauthInProgress || !pendingRequest || isRetryingOriginalRequest) {
            return;
        }

        const request = pendingRequest;
        pendingRequest = null;
        isReauthInProgress = false;

        request.reject({
            response: {
                data: {
                    reauth_cancelled: true,
                    message: "Security confirmation was cancelled.",
                },
            },
        });
    };

    const showError = function (message) {
        Swal.fire({
            text: message || "Password verification failed.",
            icon: "error",
            buttonsStyling: false,
            confirmButtonText: "Ok, got it!",
            customClass: {
                confirmButton: "btn btn-primary",
            },
        });
    };

    const verifyPassword = function () {
        validator.validate().then(function (status) {
            if (status !== "Valid") {
                return;
            }

            submitButton.setAttribute("data-kt-indicator", "on");
            submitButton.disabled = true;

            axios.post("/reauthenticate", {
                password: form.querySelector('[name="password"]').value,
            }, {
                headers: {
                    "X-CSRF-TOKEN": getCsrfToken(),
                    "Accept": "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
                skipReauthInterceptor: true,
            }).then(function () {
                if (!pendingRequest) {
                    return;
                }

                const request = pendingRequest;
                pendingRequest = null;

                isRetryingOriginalRequest = true;
                isReauthInProgress = false;

                modal.hide();

                request.config._reauthRetry = true;

                axios(request.config)
                    .then(request.resolve)
                    .catch(request.reject)
                    .finally(function () {
                        isRetryingOriginalRequest = false;
                    });
            }).catch(function (error) {
                let message = "Something went wrong. Please try again.";

                if (error.response?.data?.errors?.password) {
                    message = error.response.data.errors.password[0];
                } else if (error.response?.data?.message) {
                    message = error.response.data.message;
                }

                showError(message);
            }).finally(function () {
                submitButton.removeAttribute("data-kt-indicator");
                submitButton.disabled = false;
            });
        });
    };

    const initValidation = function () {
        validator = FormValidation.formValidation(form, {
            fields: {
                password: {
                    validators: {
                        notEmpty: {
                            message: "Password is required",
                        },
                    },
                },
            },
            plugins: {
                trigger: new FormValidation.plugins.Trigger(),
                bootstrap: new FormValidation.plugins.Bootstrap5({
                    rowSelector: ".fv-row",
                    eleInvalidClass: "",
                    eleValidClass: "",
                }),
            },
        });
    };

    const initInterceptor = function () {
        axios.interceptors.response.use(
            function (response) {
                return response;
            },
            function (error) {
                if (
                    error.config &&
                    !error.config._reauthRetry &&
                    !error.config.skipReauthInterceptor &&
                    error.response &&
                    error.response.status === 403 &&
                    error.response.data &&
                    error.response.data.reauth_required
                ) {
                    console.log(error.response);

                    return new Promise(function (resolve, reject) {
                        pendingRequest = {
                            config: error.config,
                            resolve: resolve,
                            reject: reject,
                        };

                        openModal();
                    });
                }

                return Promise.reject(error);
            }
        );
    };

    const initEvents = function () {
        submitButton.addEventListener("click", function (e) {
            e.preventDefault();
            verifyPassword();
        });

        form.addEventListener("submit", function (e) {
            e.preventDefault();
            verifyPassword();
        });

        modalElement.addEventListener("hidden.bs.modal", function () {
            cancelPendingRequest();
        });
    };

    return {
        init: function () {
            modalElement = document.querySelector("#kt_reauth_modal");
            form = document.querySelector("#kt_reauth_form");
            submitButton = document.querySelector("#kt_reauth_submit");

            if (!modalElement || !form || !submitButton) {
                return;
            }

            modal = new bootstrap.Modal(modalElement);

            initValidation();
            initInterceptor();
            initEvents();
        },
    };
}();

KTUtil.onDOMContentLoaded(function () {
    KTReauthentication.init();
});
