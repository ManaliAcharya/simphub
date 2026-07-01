"use strict";

const Login = (() => {
    let form;
    let submitButton;
    let validator;
    let turnstileWidgetId = null;

    const initElements = () => {
        form = document.querySelector("#loginForm");
        submitButton = document.querySelector("#loginSubmitBtn");
    };

    const initValidation = () => {
        validator = FormValidation.formValidation(form, {
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

    const setLoading = (loading) => {
        submitButton.disabled = loading;

        loading
            ? submitButton.setAttribute("data-kt-indicator", "on")
            : submitButton.removeAttribute("data-kt-indicator");
    };

    const showCaptcha = () => {
        const wrapper = document.getElementById("captchaWrapper");
        const container = document.getElementById("turnstileContainer");

        if (!wrapper || !container) return;

        wrapper.classList.remove("d-none");

        if (turnstileWidgetId !== null) return;

        const renderCaptcha = () => {
            if (typeof turnstile === "undefined") {
                setTimeout(renderCaptcha, 300);
                return;
            }

            turnstileWidgetId = turnstile.render("#turnstileContainer", {
                sitekey: window.turnstileSiteKey,
            });
        };

        renderCaptcha();
    };

    const resetTurnstile = () => {
        if (typeof turnstile !== "undefined" && turnstileWidgetId !== null) {
            turnstile.reset(turnstileWidgetId);
        }
    };

    const showCaptchaError = (message) => {
        const errorBox = document.getElementById("captchaError");

        if (!errorBox) return;

        errorBox.textContent = message;
        errorBox.classList.remove("d-none");
    };

    const hideCaptchaError = () => {
        const errorBox = document.getElementById("captchaError");

        if (!errorBox) return;

        errorBox.textContent = "";
        errorBox.classList.add("d-none");
    };

    const getErrorMessage = (error) => {
        if (error.response?.status === 422) {
            const errors = error.response.data.errors || {};
            const firstKey = Object.keys(errors)[0];

            if (firstKey) {
                return errors[firstKey][0];
            }
        }

        if (error.response?.status === 429) {
            return (
                error.response.data.message ||
                "Too many login attempts. Please try again later."
            );
        }

        return (
            error.response?.data?.message ||
            "Unable to sign in. Please try again."
        );
    };

    const showSuccessAlert = (message) => {
        return Swal.fire({
            icon: "success",
            title: "Signed In",
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
            title: "Sign In Failed",
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

        hideCaptchaError();

        const status = await validator.validate();

        if (status !== "Valid") {
            showErrorAlert("Please enter your email address and password.");
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

            await showSuccessAlert(response.data.message || "Login successful.");

            window.location.href = response.data.redirect_url;
        } catch (error) {
            if (error.response?.data?.require_captcha) {
                showCaptcha();
            }

            const message = getErrorMessage(error);

            if (error.response?.data?.errors?.captcha) {
                showCaptchaError(error.response.data.errors.captcha[0]);
            }

            showErrorAlert(message);
            resetTurnstile();
            setLoading(false);
        }
    };

    const initEvents = () => {
        form.addEventListener("submit", handleSubmit);
    };

    return {
        init() {
            initElements();

            if (!form || !submitButton) return;

            initValidation();
            initEvents();

            if (window.requireCaptchaOnLoad) {
                showCaptcha();
            }
        },
    };
})();

KTUtil.onDOMContentLoaded(() => {
    Login.init();
});
