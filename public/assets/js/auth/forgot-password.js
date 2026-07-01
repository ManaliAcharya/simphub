"use strict";

const ForgotPassword = (() => {
    let form;
    let formAlert;

    const steps = {};
    const buttons = {};
    const validators = {};

    const selectors = {
        form: "#forgotPasswordForm",
        formAlert: "#formAlert",

        emailStep: "#emailStep",
        codeStep: "#codeStep",
        passwordStep: "#passwordStep",

        sendCodeBtn: "#sendCodeBtn",
        verifyCodeBtn: "#verifyCodeBtn",
        resetPasswordBtn: "#resetPasswordBtn",
        changeEmailBtn: "#changeEmailBtn",
    };

    const fields = {
        email: '[name="email"]',
        code: '[name="code"]',
        password: '[name="password"]',
        passwordConfirmation: '[name="password_confirmation"]',
    };

    const initElements = () => {
        form = document.querySelector(selectors.form);
        formAlert = document.querySelector(selectors.formAlert);

        steps.email = document.querySelector(selectors.emailStep);
        steps.code = document.querySelector(selectors.codeStep);
        steps.password = document.querySelector(selectors.passwordStep);

        buttons.sendCode = document.querySelector(selectors.sendCodeBtn);
        buttons.verifyCode = document.querySelector(selectors.verifyCodeBtn);
        buttons.resetPassword = document.querySelector(selectors.resetPasswordBtn);
        buttons.changeEmail = document.querySelector(selectors.changeEmailBtn);
    };

    const field = (name) => form.querySelector(fields[name]);

    const value = (name) => field(name).value.trim();

    const showStep = (stepName) => {
        Object.values(steps).forEach((step) => step.classList.add("d-none"));
        steps[stepName].classList.remove("d-none");
    };

    const setLoading = (button, loading) => {
        button.disabled = loading;

        button.toggleAttribute("data-kt-indicator", loading);
    };

    const showInlineAlert = (message, type = "primary") => {
        formAlert.className = `alert alert-${type} d-flex align-items-center p-5 mb-8`;
        formAlert.innerHTML = `<div class="fw-semibold">${message}</div>`;
    };

    const hideInlineAlert = () => {
        formAlert.className = "alert d-none mb-8";
        formAlert.innerHTML = "";
    };

    const swal = (icon, title, text) => {
        return Swal.fire({
            icon,
            title,
            text,
            buttonsStyling: false,
            confirmButtonText: icon === "success" ? "Continue" : "Ok",
            customClass: {
                confirmButton: "btn btn-primary",
            },
        });
    };

    const showSuccessAlert = (message) => swal("success", "Success", message);

    const showErrorAlert = (message) => swal("error", "Error", message);

    const getErrorMessage = (error) => {
        const response = error.response;

        if (response?.status === 422) {
            const errors = response.data?.errors || {};
            const firstKey = Object.keys(errors)[0];

            if (firstKey && errors[firstKey]?.[0]) {
                return errors[firstKey][0];
            }
        }

        return response?.data?.message || "Something went wrong. Please try again.";
    };

    const postJson = (url, payload) => {
        return axios.post(url, payload, {
            headers: {
                "X-Requested-With": "XMLHttpRequest",
                Accept: "application/json",
            },
        });
    };

    const initValidation = () => {
        const plugins = () => ({
            trigger: new FormValidation.plugins.Trigger(),
            bootstrap: new FormValidation.plugins.Bootstrap5({
                rowSelector: ".fv-row",
                eleInvalidClass: "",
                eleValidClass: "",
            }),
        });

        validators.email = FormValidation.formValidation(form, {
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
            },
            plugins: plugins(),
        });

        validators.code = FormValidation.formValidation(form, {
            fields: {
                code: {
                    validators: {
                        notEmpty: {
                            message: "Verification code is required",
                        },
                        regexp: {
                            regexp: /^[0-9]{6}$/,
                            message: "Verification code must be 6 digits",
                        },
                    },
                },
            },
            plugins: plugins(),
        });

        validators.password = FormValidation.formValidation(form, {
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
            plugins: plugins(),
        });
    };

    const handleRequestCode = async () => {
        hideInlineAlert();

        if ((await validators.email.validate()) !== "Valid") {
            await showErrorAlert("Please enter a valid email address.");
            return;
        }

        setLoading(buttons.sendCode, true);

        try {
            const response = await postJson(window.forgotPasswordRoutes.requestCode, {
                email: value("email"),
            });

            showInlineAlert(
                response.data.message ||
                    "If an account exists, we've sent a code to that email. Enter it below."
            );

            showStep("code");
            field("code").focus();
        } catch (error) {
            await showErrorAlert(getErrorMessage(error));
        } finally {
            setLoading(buttons.sendCode, false);
        }
    };

    const handleVerifyCode = async () => {
        hideInlineAlert();

        if ((await validators.code.validate()) !== "Valid") {
            await showErrorAlert("Please enter the 6-digit verification code.");
            return;
        }

        setLoading(buttons.verifyCode, true);

        try {
            const response = await postJson(window.forgotPasswordRoutes.verifyCode, {
                email: value("email"),
                code: value("code"),
            });

            await showSuccessAlert(
                response.data.message || "Code verified. Please enter your new password."
            );

            showStep("password");
            field("password").focus();
        } catch (error) {
            await showErrorAlert(getErrorMessage(error));
        } finally {
            setLoading(buttons.verifyCode, false);
        }
    };

    const handleResetPassword = async () => {
        hideInlineAlert();

        if ((await validators.password.validate()) !== "Valid") {
            await showErrorAlert("Please fix the validation errors and try again.");
            return;
        }

        setLoading(buttons.resetPassword, true);

        try {
            const response = await postJson(window.forgotPasswordRoutes.resetPassword, {
                email: value("email"),
                code: value("code"),
                password: field("password").value,
                password_confirmation: field("passwordConfirmation").value,
            });

            await showSuccessAlert(
                response.data.message ||
                    "Password reset successful. Please login again."
            );

            window.location.href =
                response.data.redirect_url ||
                window.forgotPasswordRoutes.login ||
                "/login";
        } catch (error) {
            await showErrorAlert(getErrorMessage(error));
            setLoading(buttons.resetPassword, false);
        }
    };

    const handleChangeEmail = () => {
        hideInlineAlert();

        field("code").value = "";
        field("password").value = "";
        field("passwordConfirmation").value = "";

        showStep("email");
        field("email").focus();
    };

    const restrictCodeInput = () => {
        field("code").addEventListener("input", (event) => {
            event.target.value = event.target.value.replace(/\D/g, "").slice(0, 6);
        });
    };

    const initEvents = () => {
        buttons.sendCode.addEventListener("click", handleRequestCode);
        buttons.verifyCode.addEventListener("click", handleVerifyCode);
        buttons.resetPassword.addEventListener("click", handleResetPassword);
        buttons.changeEmail.addEventListener("click", handleChangeEmail);

        restrictCodeInput();
    };

    return {
        init() {
            initElements();

            if (
                !form ||
                !formAlert ||
                !steps.email ||
                !steps.code ||
                !steps.password ||
                !buttons.sendCode ||
                !buttons.verifyCode ||
                !buttons.resetPassword ||
                !buttons.changeEmail
            ) {
                return;
            }

            initValidation();
            initEvents();
        },
    };
})();

KTUtil.onDOMContentLoaded(() => {
    ForgotPassword.init();
});
