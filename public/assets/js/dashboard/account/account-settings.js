"use strict";

const AccountSettings = (() => {
    let emailForm;
    let emailSubmitButton;
    let emailValidator;

    let passwordForm;
    let passwordSubmitButton;
    let passwordValidator;

    let emailDisplay;
    let emailEdit;
    let emailButton;
    let emailCancel;

    let passwordDisplay;
    let passwordEdit;
    let passwordButton;
    let passwordCancel;

    const initElements = () => {
        emailForm = document.querySelector("#kt_signin_change_email");
        emailSubmitButton = document.querySelector("#kt_email_submit");

        passwordForm = document.querySelector("#kt_signin_change_password");
        passwordSubmitButton = document.querySelector("#kt_password_submit");

        emailDisplay = document.querySelector("#kt_signin_email");
        emailEdit = document.querySelector("#kt_signin_email_edit");
        emailButton = document.querySelector("#kt_signin_email_button");
        emailCancel = document.querySelector("#kt_signin_cancel");

        passwordDisplay = document.querySelector("#kt_signin_password");
        passwordEdit = document.querySelector("#kt_signin_password_edit");
        passwordButton = document.querySelector("#kt_signin_password_button");
        passwordCancel = document.querySelector("#kt_password_cancel");
    };

    const initEmailValidation = () => {
        if (!emailForm) {
            return;
        }

        emailValidator = FormValidation.formValidation(emailForm, {
            fields: {
                email: {
                    validators: {
                        notEmpty: {
                            message: "Email address is required",
                        },
                        emailAddress: {
                            message: "Please enter a valid email address",
                        },
                    },
                },
                current_password: {
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

    const initPasswordValidation = () => {
        if (!passwordForm) {
            return;
        }

        passwordValidator = FormValidation.formValidation(passwordForm, {
            fields: {
                current_password: {
                    validators: {
                        notEmpty: {
                            message: "Current password is required",
                        },
                    },
                },
                password: {
                    validators: {
                        notEmpty: {
                            message: "New password is required",
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
                                    passwordForm.querySelector('[name="password"]').value
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

    const setLoading = (button, loading) => {
        if (!button) {
            return;
        }

        button.disabled = loading;

        if (loading) {
            button.setAttribute("data-kt-indicator", "on");
            return;
        }

        button.removeAttribute("data-kt-indicator");
    };

    const isSecurityConfirmationCancelled = (error) => {
        return error.response?.data?.reauth_cancelled === true;
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
            confirmButtonText: "Ok",
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

    const toggleEmailEdit = (isEditing) => {
        emailDisplay?.classList.toggle("d-none", isEditing);
        emailButton?.classList.toggle("d-none", isEditing);
        emailEdit?.classList.toggle("d-none", !isEditing);
    };

    const togglePasswordEdit = (isEditing) => {
        passwordDisplay?.classList.toggle("d-none", isEditing);
        passwordButton?.classList.toggle("d-none", isEditing);
        passwordEdit?.classList.toggle("d-none", !isEditing);
    };

    const resetEmailForm = () => {
        emailForm?.reset();
        emailValidator?.resetForm(true);
    };

    const resetPasswordForm = () => {
        passwordForm?.reset();
        passwordValidator?.resetForm(true);
    };

    const handleEmailSubmit = async (event) => {
        event.preventDefault();

        const status = await emailValidator.validate();

        if (status !== "Valid") {
            showErrorAlert("Please fix the validation errors and try again.");
            return;
        }

        setLoading(emailSubmitButton, true);

        try {
            const response = await axios.post(emailForm.action, new FormData(emailForm), {
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    Accept: "application/json",
                },
            });

            await showSuccessAlert(
                response.data.message || "Email updated successfully."
            );

            window.location.reload();
        } catch (error) {
            setLoading(emailSubmitButton, false);

            if (isSecurityConfirmationCancelled(error)) {
                return;
            }

            showErrorAlert(getErrorMessage(error));
        }
    };

    const handlePasswordSubmit = async (event) => {
        event.preventDefault();

        const status = await passwordValidator.validate();

        if (status !== "Valid") {
            showErrorAlert("Please fix the validation errors and try again.");
            return;
        }

        setLoading(passwordSubmitButton, true);

        try {
            const response = await axios.post(passwordForm.action, new FormData(passwordForm), {
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    Accept: "application/json",
                },
            });

            await showSuccessAlert(
                response.data.message || "Password updated successfully."
            );

            resetPasswordForm();
            togglePasswordEdit(false);
            setLoading(passwordSubmitButton, false);
        } catch (error) {
            setLoading(passwordSubmitButton, false);

            if (isSecurityConfirmationCancelled(error)) {
                return;
            }

            showErrorAlert(getErrorMessage(error));
        }
    };

    const initEvents = () => {
        emailButton?.addEventListener("click", () => toggleEmailEdit(true));

        emailCancel?.addEventListener("click", () => {
            resetEmailForm();
            toggleEmailEdit(false);
        });

        passwordButton?.addEventListener("click", () => togglePasswordEdit(true));

        passwordCancel?.addEventListener("click", () => {
            resetPasswordForm();
            togglePasswordEdit(false);
        });

        emailForm?.addEventListener("submit", handleEmailSubmit);
        passwordForm?.addEventListener("submit", handlePasswordSubmit);
    };

    return {
        init() {
            initElements();

            if (!emailForm && !passwordForm) {
                return;
            }

            initEmailValidation();
            initPasswordValidation();
            initEvents();
        },
    };
})();

KTUtil.onDOMContentLoaded(() => {
    AccountSettings.init();
});
