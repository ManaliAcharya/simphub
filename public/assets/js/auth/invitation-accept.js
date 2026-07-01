"use strict";

const InvitationAccept = (() => {
    let form;
    let submitButton;
    let validator;

    const initElements = () => {
        form = document.querySelector("#resetPasswordForm");
        submitButton = document.querySelector("#submitBtn");
    };

    const initValidation = () => {
        validator = FormValidation.formValidation(form, {
            fields: {
                password: {
                    validators: {
                        notEmpty: {
                            message: "Password is required",
                        },
                        stringLength: {
                            min: 12,
                            message: "Password must be at least 12 characters",
                        },
                    },
                },
                password_confirmation: {
                    validators: {
                        notEmpty: {
                            message: "Confirm password is required",
                        },
                        callback: {
                            message: "Password and confirm password must match",
                            callback: function (input) {

                                if (!input.value) {
                                    return true;
                                }

                                return (
                                    input.value ===
                                    form.querySelector('[name="password"]').value
                                );
                            },
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

    const setLoading = (loading) => {
        submitButton.disabled = loading;

        loading
            ? submitButton.setAttribute("data-kt-indicator", "on")
            : submitButton.removeAttribute("data-kt-indicator");
    };

    const getErrorMessage = (error) => {
        if (error.response?.status === 422) {
            const errors = error.response.data.errors || {};
            const firstKey = Object.keys(errors)[0];

            if (firstKey) {
                return errors[firstKey][0];
            }
        }

        return (
            error.response?.data?.message ||
            "Something went wrong. Please try again."
        );
    };

    const showSuccessAlert = (message) => {
        return Swal.fire({
            icon: "success",
            title: "Success",
            text: message,
            buttonsStyling: false,
            confirmButtonText: "Continue",
            customClass: {
                confirmButton: "btn btn-primary",
            },
        });
    };

    const showErrorAlert = (message) => {
        return Swal.fire({
            icon: "error",
            title: "Error",
            text: message,
            buttonsStyling: false,
            confirmButtonText: "Ok",
            customClass: {
                confirmButton: "btn btn-primary",
            },
        });
    };

    const handleSubmit = async (event) => {
        event.preventDefault();

        const status = await validator.validate();

        if (status !== "Valid") {
            showErrorAlert("Please fix the validation errors and try again.");
            return;
        }

        setLoading(true);

        try {
            const response = await axios.post(form.action, new FormData(form), {
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    Accept: "application/json",
                },
            });

            await showSuccessAlert(
                response.data.message || "Password created successfully."
            );

            console.log(response.data);

            window.location.href = response.data.redirect_url || "/dashboard";
        } catch (error) {
            showErrorAlert(getErrorMessage(error));
            setLoading(false);
        }
    };

    const initEvents = () => {
        form.addEventListener("submit", handleSubmit);
    };

    return {
        init() {
            initElements();

            if (!form || !submitButton) {
                return;
            }

            initValidation();
            initEvents();
        },
    };
})();

KTUtil.onDOMContentLoaded(() => {
    InvitationAccept.init();
});
